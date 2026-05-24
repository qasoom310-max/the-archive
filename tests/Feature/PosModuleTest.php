<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Enums\ModuleState;
use App\Erp\Modules\ModuleManager;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Models\Ir\IrModel;
use App\Models\Ir\IrModule;
use App\Models\Ir\IrUiView;
use App\Models\UserViewPreference;
use App\Models\Mail\MessageType;
use App\Livewire\Views\FormView;
use App\Livewire\Views\ListView;
use App\Models\User;
use Database\Seeders\AuthSeeder;
use Database\Seeders\PosSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Livewire\PosCategories;
use Modules\Pos\Livewire\PosHome;
use Modules\Pos\Livewire\PosProductForm;
use Modules\Pos\Livewire\PosSessionPage;
use Modules\Pos\Livewire\PosTerminal;
use Modules\Pos\Models\PosCategory;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosPaymentMethod;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Livewire\PosProductImport;
use Modules\Pos\Models\PosProductRecipe;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Services\PosProductImporter;
use Tests\TestCase;

final class PosModuleTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function installPos(): void
    {
        app(ModuleManager::class)->install('pos');
    }

    private function openSession(float $opening = 50.0): PosSession
    {
        return PosSession::query()->create([
            'reference' => 'POS-S/0001',
            'state' => SessionState::Opened,
            'opening_cash' => $opening,
            'opened_at' => now(),
        ]);
    }

    public function test_install_resolves_contacts_dependency_and_registers_models(): void
    {
        $this->installPos();

        $this->assertSame(ModuleState::Installed, IrModule::query()->where('name', 'pos')->sole()->state);
        // depends: ["base","contacts"] → contacts auto-installed first
        $this->assertSame(ModuleState::Installed, IrModule::query()->where('name', 'contacts')->sole()->state);

        $this->assertTrue(Schema::hasTable('pos_orders'));
        $this->assertTrue(Schema::hasTable('partners')); // from the contacts dependency

        $this->assertEqualsCanonicalizing(
            ['pos.product', 'pos.category', 'pos.session', 'pos.order'],
            IrModel::query()->where('module', 'pos')->pluck('model')->all(),
        );
        $this->assertTrue(
            IrUiView::query()->where('model', 'pos.order')->where('type', 'kanban')->exists(),
        );
    }

    public function test_line_totals_apply_discount_then_tax(): void
    {
        $this->installPos();
        $session = $this->openSession();
        $product = PosProduct::query()->create(['name' => 'Coffee', 'price' => 10, 'tax_rate' => 10]);

        $c = Livewire::test(PosTerminal::class, ['session' => $session->id]);
        $c->call('addProduct', $product->id);
        $c->call('addProduct', $product->id); // qty 2

        $order = PosOrder::query()->where('pos_session_id', $session->id)->sole();
        $line = $order->lines()->sole();

        $this->assertEqualsWithDelta(2.0, $line->qty, 0.001);
        $this->assertEqualsWithDelta(20.0, $order->subtotal, 0.01);
        $this->assertEqualsWithDelta(2.0, $order->tax_total, 0.01);
        $this->assertEqualsWithDelta(22.0, $order->total, 0.01);

        $c->call('setDiscount', $line->id, 10); // 10% off → net 18, tax 1.8, total 19.8
        $order->refresh();
        $this->assertEqualsWithDelta(18.0, $order->subtotal, 0.01);
        $this->assertEqualsWithDelta(1.8, $order->tax_total, 0.01);
        $this->assertEqualsWithDelta(19.8, $order->total, 0.01);
    }

    public function test_product_form_save_with_category_does_not_fail_string_validation(): void
    {
        // Regression: engine FormView used to apply a default `string` rule to
        // every non-image widget, breaking many2one selects whose value is an int.
        $this->installPos();
        $category = PosCategory::query()->create(['name' => 'Shisha']);

        Livewire::test(FormView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ])
            ->set('form.name', 'Maassel')
            ->set('form.price', 8.0)
            ->set('form.pos_category_id', $category->id) // int FK — must validate
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            $category->id,
            PosProduct::query()->where('name->en', 'Maassel')->sole()->pos_category_id,
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function imageFormatProvider(): array
    {
        // Every extension we now claim to accept (FormView::rules + the
        // "Accepted: …" hint in form-view.blade.php + livewire.preview_mimes).
        // Keep these in sync — adding a format here without updating those
        // three places will fail this test, which is the point.
        //
        // SVG deliberately absent — stored-XSS risk via inline <script>.
        return [
            'jpg'  => ['photo.jpg',  'image/jpeg', 'Burger'],
            'jpeg' => ['photo.jpeg', 'image/jpeg', 'Pizza'],
            'png'  => ['photo.png',  'image/png',  'Salad'],
            'gif'  => ['photo.gif',  'image/gif',  'Soup'],
            'webp' => ['photo.webp', 'image/webp', 'حليب جنزبيل'], // ← user's actual case
            'bmp'  => ['photo.bmp',  'image/bmp',  'Bread'],
            'avif' => ['photo.avif', 'image/avif', 'Pepsi'],
            'heic' => ['photo.heic', 'image/heic', 'Coffee'],
            'heif' => ['photo.heif', 'image/heif', 'Tea'],
        ];
    }

    /**
     * @dataProvider imageFormatProvider
     */
    public function test_form_image_upload_controller_accepts_every_claimed_format(string $filename, string $mime, string $productName): void
    {
        // Exhaustive coverage of every format the direct-upload controller
        // accepts (FormImageUploadController, hit from the Blade's Alpine
        // wrapper). Includes the WebP + Arabic-product-name combo that
        // reported a 500 in production — this path replaced Livewire's
        // unreliable signed-URL upload, so pinning every row prevents
        // regressions on shared hosts where the old flow silently failed.
        $this->installPos();
        Storage::fake('public');

        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        $response = $this->post(route('form.upload-image'), [
            'bucket' => 'pos_products',
            'file' => UploadedFile::fake()->create($filename, 8, $mime),
        ]);

        $response->assertOk()->assertJsonStructure(['path', 'url']);

        /** @var string $path */
        $path = $response->json('path');
        // Symfony normalises `.jpeg` to `.jpg` at storage time — accept both.
        $expected = $extension === 'jpeg' ? 'jpg' : $extension;
        $this->assertStringEndsWith('.' . $expected, $path);
        $this->assertStringStartsWith('pos_products/', $path);
        Storage::disk('public')->assertExists($path);

        // The Blade Alpine wrapper would normally do this; replicate the
        // contract by writing the stored path onto the component property
        // and saving, then asserting the record persists with that path.
        Livewire::test(FormView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ])
            ->set('form.name', $productName)
            ->set('form.price', 1.0)
            ->set('imagePaths.image_path', $path)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('record-saved');

        $product = PosProduct::query()->where('name->en', $productName)->sole();
        $this->assertSame($path, $product->image_path);
    }

    public function test_form_image_upload_controller_requires_auth(): void
    {
        // Override setUp's actingAs by logging out for this test only.
        Auth::logout();

        $this->post(route('form.upload-image'), [
            'bucket' => 'pos_products',
            'file' => UploadedFile::fake()->create('photo.webp', 8, 'image/webp'),
        ])->assertRedirect(route('login'));
    }

    public function test_form_image_upload_controller_rejects_unknown_bucket(): void
    {
        // The bucket whitelist prevents path-traversal writes via this
        // public endpoint. An unknown / attacker-supplied bucket must 422.
        // Accept: application/json is what the Alpine fetch sends, so the
        // controller returns 422 with the validation envelope (vs a 302
        // redirect for a browser HTML form post).
        $this->postJson(route('form.upload-image'), [
            'bucket' => '../../../etc',
            'file' => UploadedFile::fake()->create('photo.webp', 8, 'image/webp'),
        ])->assertStatus(422);

        $this->postJson(route('form.upload-image'), [
            'bucket' => 'not_a_real_bucket',
            'file' => UploadedFile::fake()->create('photo.webp', 8, 'image/webp'),
        ])->assertStatus(422);
    }

    public function test_form_image_upload_controller_rejects_oversize_file(): void
    {
        Storage::fake('public');

        // 5 MB > the 4 MB cap.
        $this->postJson(route('form.upload-image'), [
            'bucket' => 'pos_products',
            'file' => UploadedFile::fake()->create('big.webp', 5120, 'image/webp'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_form_image_upload_controller_rejects_svg(): void
    {
        // SVG is dropped from the allowlist (stored-XSS risk via inline
        // <script> when another user opens the file URL). Verify it 422s
        // — if a future tidy "helpfully" re-adds SVG, this fails first.
        Storage::fake('public');

        $this->postJson(route('form.upload-image'), [
            'bucket' => 'pos_products',
            'file' => UploadedFile::fake()->create('vector.svg', 8, 'image/svg+xml'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_product_form_surfaces_validation_error_when_upload_temp_file_missing(): void
    {
        // Regression: a corrupt Livewire snapshot (the file in livewire-tmp
        // got swept by cleanupOldUploads, or the snapshot rehydrated with a
        // malformed path) used to 500 inside Flysystem with
        // UnableToRetrieveMetadata when validate() called getSize(). The
        // guard in save() now detects this and surfaces a regular validation
        // error so the user can re-pick the file.
        $this->installPos();
        Storage::fake('public');
        Storage::fake('tmp-for-tests'); // Livewire's test temp disk

        $file = UploadedFile::fake()->create('photo.webp', 8, 'image/webp');

        $form = Livewire::test(FormView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ])
            ->set('form.name', 'Drink')
            ->set('form.price', 1.0)
            ->set('uploads.image_path', $file);

        // Simulate the temp file vanishing under us (cleanup race / 24h sweep).
        Storage::fake('tmp-for-tests');

        $form->call('save')
            ->assertHasErrors(['uploads.image_path']);

        // No record was created — save aborted before any DB write.
        $this->assertSame(0, PosProduct::query()->where('name->en', 'Drink')->count());
    }

    // ---- Column picker (UserViewPreference) ---------------------------

    public function test_pos_product_list_hides_default_hidden_columns_on_first_visit(): void
    {
        // Fresh user, no UserViewPreference row yet. The list arch marks
        // profit / tax_rate / stock_on_hand / barcode as hidden_by_default —
        // those should be off out of the box, while name / category / price
        // / cost / available_servings / active stay visible.
        $this->installPos();
        $this->seed(PosSeeder::class);

        $component = Livewire::test(ListView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ]);

        $hidden = $component->get('hiddenColumns');
        sort($hidden);
        $this->assertSame(
            ['barcode', 'profit', 'stock_on_hand', 'tax_rate'],
            $hidden,
            'Default hidden set must match the arch hidden_by_default flags.',
        );

        $visibleFields = array_map(static fn ($c) => $c->field, $component->instance()->visibleColumns());
        $this->assertContains('name', $visibleFields);
        $this->assertContains('category_name', $visibleFields);
        $this->assertContains('price', $visibleFields);
        $this->assertNotContains('tax_rate', $visibleFields);
        $this->assertNotContains('barcode', $visibleFields);
    }

    public function test_pos_product_list_category_column_renders_category_name(): void
    {
        $this->installPos();
        $this->seed(PosSeeder::class);

        $cat = \Modules\Pos\Models\PosCategory::query()->create(['name' => 'Hot Drinks']);
        PosProduct::query()->create([
            'name' => 'Latte',
            'price' => 4.0,
            'tax_rate' => 0,
            'pos_category_id' => $cat->id,
        ]);

        $html = Livewire::test(ListView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ])->html();

        // Category column header AND the per-row category name must render.
        $this->assertStringContainsString('Category', $html);
        $this->assertStringContainsString('Hot Drinks', $html);
    }

    public function test_pos_product_list_toggle_column_persists_per_user(): void
    {
        // toggleColumn flips visibility AND writes a UserViewPreference row.
        // A second mount (simulating a fresh page load) reads the saved set.
        $this->installPos();
        $this->seed(PosSeeder::class);

        $userId = (int) Auth::id();

        Livewire::test(ListView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ])
            ->call('toggleColumn', 'price')   // hide it
            ->call('toggleColumn', 'tax_rate'); // show it (was default-hidden)

        $pref = UserViewPreference::query()
            ->where('user_id', $userId)
            ->where('model_key', 'pos.product')
            ->sole();

        $this->assertContains('price', $pref->hidden_columns);
        $this->assertNotContains('tax_rate', $pref->hidden_columns);

        // Fresh mount picks up the saved state.
        $fresh = Livewire::test(ListView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ]);
        $this->assertContains('price', $fresh->get('hiddenColumns'));
        $this->assertNotContains('tax_rate', $fresh->get('hiddenColumns'));
    }

    public function test_pos_product_list_toggle_column_ignores_unknown_field(): void
    {
        // Defensive: a tampered request setting an unknown field must
        // not pollute the user's prefs row.
        $this->installPos();
        $this->seed(PosSeeder::class);

        Livewire::test(ListView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ])->call('toggleColumn', 'evil; DROP TABLE users');

        $this->assertSame(0, UserViewPreference::query()->count());
    }

    public function test_pos_product_list_reorder_persists_and_applies(): void
    {
        // reorderColumns saves the picked order. visibleColumns() then
        // renders columns in that order. Unknown / extra fields in the
        // posted array are stripped; arch columns not in the array are
        // appended at the end so nothing is permanently lost.
        $this->installPos();
        $this->seed(PosSeeder::class);

        $component = Livewire::test(ListView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ])->call('reorderColumns', ['active', 'name', 'evil_field']);

        $pref = UserViewPreference::query()->sole();
        $this->assertSame('active', $pref->column_order[0]);
        $this->assertSame('name', $pref->column_order[1]);
        $this->assertNotContains('evil_field', $pref->column_order);
        // Untouched arch columns still appear at the end.
        $this->assertContains('price', $pref->column_order);
        $this->assertContains('barcode', $pref->column_order);

        $visible = array_map(static fn ($c) => $c->field, $component->instance()->visibleColumns());
        $this->assertSame('active', $visible[0]);
        $this->assertSame('name', $visible[1]);
    }

    public function test_pos_product_list_two_users_have_independent_column_prefs(): void
    {
        $this->installPos();
        $this->seed(PosSeeder::class);

        // First user (the one acting from setUp): hide name.
        Livewire::test(ListView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ])->call('toggleColumn', 'name');

        $firstUserId = (int) Auth::id();

        // Second user: act as a separate admin.
        $second = User::factory()->create(['is_admin' => true]);
        $this->actingAs($second);

        $secondView = Livewire::test(ListView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ]);

        // Second user sees the default-hidden set, NOT the first user's
        // 'name' hide. Different row keyed by user_id.
        $this->assertNotContains('name', $secondView->get('hiddenColumns'));

        // Both rows coexist.
        $this->assertSame(1, UserViewPreference::query()->where('user_id', $firstUserId)->count());

        $secondView->call('toggleColumn', 'price');
        $this->assertSame(2, UserViewPreference::query()->count());
    }

    // ---- Kanban product card ------------------------------------------

    public function test_pos_product_kanban_card_renders_image_name_price_and_stock(): void
    {
        // Odoo-style product card. The arch declares card.image=image_path
        // plus a meta footer with price (money-formatted) and stock_on_hand
        // (number-formatted). Verify each piece lands in the rendered HTML.
        $this->installPos();
        Storage::fake('public');
        // Pin currency to BHD so the assertion below matches "4.50 BD".
        // Test bootstrap defaults to USD; the meta formatter uses the
        // active currency setting.
        \App\Erp\Settings\Setting::set('currency.default', 'BHD');
        \App\Erp\Money\Currencies::flushCache();

        // Seed one realistic product with a stored image. The fake disk
        // is just for URL generation; the file doesn't need real bytes
        // for the Blade to render an <img> tag.
        PosProduct::query()->create([
            'name' => 'Cappuccino', 'price' => 4.50, 'tax_rate' => 0,
            'cost_price' => 1.20, 'stock_on_hand' => 42, 'image_path' => 'pos_products/cappa.jpg',
        ]);

        $html = Livewire::test(\App\Livewire\Views\KanbanView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ])->html();

        // Image hero is present and points at the public-disk URL.
        $this->assertStringContainsString('pos_products/cappa.jpg', $html);
        // Name (the card title).
        $this->assertStringContainsString('Cappuccino', $html);
        // Money-formatted price (current BHD: "4.50 BD").
        $this->assertStringContainsString('4.50 BD', $html);
        // Number-formatted stock + its label.
        $this->assertStringContainsString('On hand', $html);
        $this->assertStringContainsString('42', $html);
    }

    public function test_pos_product_kanban_card_renders_placeholder_when_image_missing(): void
    {
        // image_path null on a product → the arch still declared card.image,
        // so the hero slot renders (so heights match across cards) but
        // shows the SVG placeholder, not a broken <img>.
        $this->installPos();
        Storage::fake('public');

        PosProduct::query()->create([
            'name' => 'Tea', 'price' => 1.0, 'tax_rate' => 0,
        ]); // no image_path

        $html = Livewire::test(\App\Livewire\Views\KanbanView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ])->html();

        $this->assertStringContainsString('Tea', $html);
        // No <img> tag means the placeholder branch fired.
        $this->assertStringNotContainsString('<img src=', $html);
    }

    // ---- Toolbar search bar -------------------------------------------

    public function test_pos_product_list_search_filters_by_name_or_barcode(): void
    {
        // Arch declares `searchable: [name, barcode]`. Setting `$search`
        // applies a single OR-grouped LIKE across both fields; rows that
        // match either are kept, others are dropped. Empty search = no
        // filtering (regression guard for the early-return in applySearch).
        $this->installPos();
        PosProduct::query()->create(['name' => 'Espresso', 'barcode' => 'ESP-1', 'price' => 1, 'tax_rate' => 0]);
        PosProduct::query()->create(['name' => 'Latte',    'barcode' => 'LAT-1', 'price' => 1, 'tax_rate' => 0]);
        PosProduct::query()->create(['name' => 'Croissant','barcode' => 'CRO-9', 'price' => 1, 'tax_rate' => 0]);

        $component = Livewire::test(ListView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ]);

        // Name substring → matches only Espresso.
        $html = $component->set('search', 'spress')->html();
        $this->assertStringContainsString('Espresso', $html);
        $this->assertStringNotContainsString('Latte', $html);
        $this->assertStringNotContainsString('Croissant', $html);

        // Barcode substring → matches only the one with CRO- prefix.
        $html = $component->set('search', 'CRO-')->html();
        $this->assertStringContainsString('Croissant', $html);
        $this->assertStringNotContainsString('Espresso', $html);

        // Clearing the box restores the full set.
        $html = $component->set('search', '')->html();
        $this->assertStringContainsString('Espresso', $html);
        $this->assertStringContainsString('Latte', $html);
        $this->assertStringContainsString('Croissant', $html);
    }

    public function test_pos_product_list_renders_search_input_when_arch_declares_searchable(): void
    {
        // Engine contract: arch.searchable non-empty → toolbar renders an
        // <input> bound to `search`; empty → no input renders. PosProduct
        // declares ['name', 'barcode'] so the input must be present.
        $this->installPos();

        $html = Livewire::test(ListView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ])->html();

        $this->assertStringContainsString('wire:model.live.debounce.300ms="search"', $html);
    }

    // ---- Inline boolean toggle ----------------------------------------

    public function test_pos_product_list_toggle_active_flips_and_persists(): void
    {
        // The `active` column's arch declares `format: toggle`, so the
        // engine renders it as an interactive switch + accepts inline
        // ListView::toggleBoolean calls. One click → the product flips.
        $this->installPos();
        $product = PosProduct::query()->create(['name' => 'Tea', 'price' => 1.0, 'tax_rate' => 0, 'active' => true]);

        Livewire::test(ListView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ])->call('toggleBoolean', $product->id, 'active');

        $this->assertFalse((bool) $product->fresh()->active);
    }

    public function test_pos_product_list_toggle_boolean_rejects_non_toggle_columns(): void
    {
        // Arch whitelist: only columns declared with format='toggle' are
        // flippable. A tampered request naming any other field (e.g. id,
        // pos_category_id, name) must no-op rather than write through.
        $this->installPos();
        $cat = \Modules\Pos\Models\PosCategory::query()->create(['name' => 'X']);
        $product = PosProduct::query()->create([
            'name' => 'Tea', 'price' => 1.0, 'tax_rate' => 0, 'active' => true,
            'pos_category_id' => $cat->id,
        ]);

        Livewire::test(ListView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ])->call('toggleBoolean', $product->id, 'pos_category_id');

        // Category FK unchanged; product still active.
        $this->assertSame($cat->id, $product->fresh()->pos_category_id);
        $this->assertTrue((bool) $product->fresh()->active);
    }

    public function test_pos_product_list_toggle_boolean_requires_write_permission(): void
    {
        // A pos_user (cashier) has no ACL on pos.product at all (per
        // PosStaffSeeder). toggleBoolean must reject with AuthorizationException
        // so the cashier can't disable products by spoofing a wire:click.
        $this->installPos();
        $this->seed(PosSeeder::class); // sets up pos_user group + ACLs

        $cashier = User::factory()->create(['is_admin' => false]);
        $cashier->groups()->attach(\App\Models\Auth\Group::query()->where('code', 'pos_user')->sole());
        $this->actingAs($cashier);

        $product = PosProduct::query()->create(['name' => 'Tea', 'price' => 1.0, 'tax_rate' => 0, 'active' => true]);

        Livewire::test(ListView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ])->call('toggleBoolean', $product->id, 'active')
          ->assertForbidden();

        $this->assertTrue((bool) $product->fresh()->active); // unchanged
    }

    public function test_product_form_accepts_photo_upload(): void
    {
        $this->installPos();
        Storage::fake('public');

        $form = Livewire::test(FormView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ]);

        $form->set('form.name', 'Burger')
            ->set('form.price', 9.5)
            // ->create (not ->image) so this passes on hosts without the GD extension
            // — the `image` validation rule accepts it via the explicit MIME.
            ->set('uploads.image_path', UploadedFile::fake()->create('burger.jpg', 8, 'image/jpeg'))
            ->call('save')
            ->assertHasNoErrors()
            // Save-confirmation contract: FormView::save dispatches `record-saved`,
            // which the shared layout's Alpine toast listens for (and a session
            // 'toast' flash is also set so the toast survives the wire:navigate
            // redirect). The event is the testable source of truth — Livewire's
            // test harness ages session flash data out between the inner request
            // and the assertion.
            ->assertDispatched('record-saved');

        $product = PosProduct::query()->where('name->en', 'Burger')->sole();
        $this->assertNotNull($product->image_path);
        $this->assertStringStartsWith('pos_products/', $product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
    }

    public function test_update_quantity_steps_up_down_and_removes_line_at_zero(): void
    {
        $this->installPos();
        $session = $this->openSession();
        $product = PosProduct::query()->create(['name' => 'Bun', 'price' => 5, 'tax_rate' => 0]);

        $c = Livewire::test(PosTerminal::class, ['session' => $session->id]);
        $c->call('addProduct', $product->id); // qty 1

        $order = PosOrder::query()->where('pos_session_id', $session->id)->sole();
        $line = $order->lines()->sole();

        $c->call('updateQuantity', $line->id, true);  // +1 → 2
        $this->assertEqualsWithDelta(2.0, $line->fresh()->qty, 0.001);

        $c->call('updateQuantity', $line->id, false); // −1 → 1
        $this->assertEqualsWithDelta(1.0, $line->fresh()->qty, 0.001);

        $c->call('updateQuantity', $line->id, false); // −1 → 0 → line removed
        $this->assertNull($line->fresh());
        $this->assertSame(0, $order->lines()->count());
        $this->assertEqualsWithDelta(0.0, $order->refresh()->total, 0.01);
    }

    public function test_payment_validates_order_with_change_and_chatter(): void
    {
        $this->installPos();
        $session = $this->openSession();
        PosPaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true, 'sequence' => 10]);
        $product = PosProduct::query()->create(['name' => 'Tea', 'price' => 10, 'tax_rate' => 0]);

        $c = Livewire::test(PosTerminal::class, ['session' => $session->id]);
        $c->call('addProduct', $product->id);
        $c->call('startPayment');
        $c->set('tendered', '15')->call('addPayment');
        $c->call('validateOrder');

        $order = PosOrder::query()->where('pos_session_id', $session->id)->sole();
        $this->assertSame(OrderState::Done, $order->state);
        $this->assertEqualsWithDelta(5.0, $order->change_due, 0.01);
        $this->assertNotNull($order->ordered_at);
        $this->assertTrue(
            $order->messages()->where('type', MessageType::Log)
                ->where('body', 'like', '%paid%')->exists(),
        );
    }

    public function test_session_close_reconciles_cash(): void
    {
        $this->installPos();
        $session = $this->openSession(50.0);
        $cash = PosPaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true]);

        $order = PosOrder::query()->create([
            'reference' => 'POS/1/0001',
            'pos_session_id' => $session->id,
            'state' => OrderState::Draft,
            'total' => 22,
        ]);
        $order->registerPayment($cash, 22.0);
        $order->markPaid();
        $order->state = OrderState::Done;
        $order->save();

        $this->assertEqualsWithDelta(72.0, $session->expectedCash(), 0.01); // 50 + 22

        $session->close(72.0);
        $session->refresh();

        $this->assertSame(SessionState::Closed, $session->state);
        $this->assertEqualsWithDelta(0.0, $session->cash_difference, 0.01);
        $this->assertTrue(
            $session->messages()->where('body', 'like', '%closed%')->exists(),
        );
    }

    public function test_pos_user_acl_grants_operate_but_not_delete(): void
    {
        $this->installPos();
        $this->seed(AuthSeeder::class);
        $this->seed(PosSeeder::class);

        $sales = User::query()->where('email', 'sales@example.com')->sole();
        $acl = app(AccessControl::class);

        // Orders + sessions: full operate, never delete.
        $this->assertTrue($acl->allows($sales, 'pos.order', Permission::Create));
        $this->assertTrue($acl->allows($sales, 'pos.order', Permission::Write));
        $this->assertTrue($acl->allows($sales, 'pos.session', Permission::Create));
        $this->assertFalse($acl->allows($sales, 'pos.order', Permission::Unlink));

        // Product catalogue: NO access at all (orders only).
        $this->assertFalse($acl->allows($sales, 'pos.product', Permission::Read));
        $this->assertFalse($acl->allows($sales, 'pos.product', Permission::Write));
        $this->assertFalse($acl->allows($sales, 'pos.product', Permission::Create));
        $this->assertFalse($acl->allows($sales, 'pos.product', Permission::Unlink));
    }

    public function test_cashier_cannot_delete_products(): void
    {
        $this->installPos();
        $this->seed(AuthSeeder::class);
        $this->seed(PosSeeder::class);

        $this->actingAs(User::query()->where('email', 'sales@example.com')->sole());

        $product = PosProduct::query()->create(['name' => 'Keepme', 'price' => 1, 'tax_rate' => 0]);

        Livewire::test(ListView::class, ['model' => PosProduct::class, 'modelKey' => 'pos.product'])
            ->set('selected', [$product->id])
            ->call('bulkDelete')
            ->assertForbidden();

        // `name` is now a translatable JSON column; assert via the JSON-path
        // query instead of a literal column-equality match.
        $this->assertTrue(PosProduct::query()->where('name->en', 'Keepme')->exists());
    }

    public function test_cashier_cannot_add_a_product(): void
    {
        $this->installPos();
        $this->seed(AuthSeeder::class);
        $this->seed(PosSeeder::class);

        $this->actingAs(User::query()->where('email', 'sales@example.com')->sole());

        Livewire::test(FormView::class, ['model' => PosProduct::class, 'modelKey' => 'pos.product'])
            ->set('form.name', 'Contraband')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseMissing('pos_products', ['name' => 'Contraband']);
    }

    public function test_pos_home_and_session_pages_render(): void
    {
        $this->installPos();
        $this->seed(PosSeeder::class);

        Livewire::test(PosHome::class)->assertOk()->assertSee('Point of Sale');

        $session = $this->openSession();
        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->assertOk()
            ->assertSee('Espresso'); // seeded product
    }

    public function test_session_is_a_global_singleton_joined_by_any_user(): void
    {
        $this->installPos();
        $this->seed(AuthSeeder::class);
        $this->seed(PosSeeder::class);

        $admin = User::query()->where('email', 'admin@example.com')->sole();
        $sales = User::query()->where('email', 'sales@example.com')->sole();

        // Admin opens the register…
        $this->actingAs($admin);
        Livewire::test(PosHome::class)->set('openingCash', '10')->call('openSession');

        // …a *different* user "opening" must JOIN it, not create a 2nd.
        $this->actingAs($sales);
        Livewire::test(PosHome::class)->set('openingCash', '99')->call('openSession');

        $this->assertSame(1, PosSession::query()->where('state', SessionState::Opened)->count());
        // The single register kept the original float (not replaced).
        $this->assertEqualsWithDelta(
            10.0,
            PosSession::query()->where('state', SessionState::Opened)->sole()->opening_cash,
            0.01,
        );
    }

    public function test_any_authorized_cashier_joins_shared_session_and_order_is_attributed(): void
    {
        $this->installPos();
        $this->seed(AuthSeeder::class);
        $this->seed(PosSeeder::class);

        // Register opened by someone else (no per-user owner anymore).
        $session = $this->openSession();

        // A different ACL-authorised cashier may sell on the shared session.
        $sales = User::query()->where('email', 'sales@example.com')->sole();
        $this->actingAs($sales);
        $product = PosProduct::query()->create(['name' => 'Latte', 'price' => 5, 'tax_rate' => 0]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->assertOk() // NOT forbidden — shared register
            ->call('addProduct', $product->id);

        $order = PosOrder::query()->where('pos_session_id', $session->id)->sole();
        // Individual accountability preserved on the order.
        $this->assertSame($sales->id, $order->user_id);
        $this->assertSame($sales->id, $order->user?->id);
    }

    public function test_selling_a_product_statically_consumes_its_components(): void
    {
        $this->installPos();
        $session = $this->openSession();

        $shisha = PosProduct::query()->create(['name' => 'Shisha', 'price' => 20, 'tax_rate' => 0]);
        $tobacco = PosProduct::query()->create(['name' => 'Tobacco', 'price' => 0, 'tax_rate' => 0, 'stock_on_hand' => 100]);
        $charcoal = PosProduct::query()->create(['name' => 'Charcoal', 'price' => 0, 'tax_rate' => 0, 'stock_on_hand' => 50]);

        PosProductRecipe::query()->create(['parent_product_id' => $shisha->id, 'component_product_id' => $tobacco->id, 'quantity_consumed' => 2]);
        PosProductRecipe::query()->create(['parent_product_id' => $shisha->id, 'component_product_id' => $charcoal->id, 'quantity_consumed' => 3]);

        PosPaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true]);

        $c = Livewire::test(PosTerminal::class, ['session' => $session->id]);
        $c->call('addProduct', $shisha->id);
        $c->call('addProduct', $shisha->id); // qty 2
        $c->call('startPayment');
        $c->set('tendered', '40')->call('addPayment');
        $c->call('validateOrder');

        // Sold 2 → Tobacco 100 − 2×2 = 96 ; Charcoal 50 − 3×2 = 44
        $this->assertEqualsWithDelta(96.0, $tobacco->refresh()->stock_on_hand, 0.001);
        $this->assertEqualsWithDelta(44.0, $charcoal->refresh()->stock_on_hand, 0.001);

        $order = PosOrder::query()->where('pos_session_id', $session->id)->sole();
        $this->assertSame(OrderState::Done, $order->state);
        $this->assertTrue($order->components_consumed);

        // Re-validating must NOT double-consume (idempotent + atomic).
        $c->call('validateOrder');
        $this->assertEqualsWithDelta(96.0, $tobacco->refresh()->stock_on_hand, 0.001);
        $this->assertEqualsWithDelta(44.0, $charcoal->refresh()->stock_on_hand, 0.001);
    }

    public function test_theoretical_yield_and_available_servings(): void
    {
        $this->installPos();

        $shisha = PosProduct::query()->create(['name' => 'Shisha', 'price' => 20, 'tax_rate' => 0]);
        $tobacco = PosProduct::query()->create(['name' => 'Tobacco', 'price' => 0, 'tax_rate' => 0, 'stock_on_hand' => 9]);
        $charcoal = PosProduct::query()->create(['name' => 'Charcoal', 'price' => 0, 'tax_rate' => 0, 'stock_on_hand' => 30]);

        PosProductRecipe::query()->create(['parent_product_id' => $shisha->id, 'component_product_id' => $tobacco->id, 'quantity_consumed' => 2]);
        PosProductRecipe::query()->create(['parent_product_id' => $shisha->id, 'component_product_id' => $charcoal->id, 'quantity_consumed' => 3]);

        // Limiting component caps it: floor(9/2)=4 vs floor(30/3)=10 → 4
        $this->assertSame(4, $shisha->theoreticalYield());
        $this->assertSame(4, $shisha->available_servings);

        // A product without a recipe is not made-to-order → null.
        $plain = PosProduct::query()->create(['name' => 'Water', 'price' => 1, 'tax_rate' => 0]);
        $this->assertNull($plain->theoreticalYield());
        $this->assertNull($plain->available_servings);
    }

    public function test_receipt_panel_shows_customer_phone_and_12_hour_time(): void
    {
        // The receipt overlay must print the captured customer phone
        // (prefixed with "+") and the order datetime in 12-hour format.
        // Together these are the two visible signals that the on-screen
        // receipt matches what gets sent to WhatsApp.
        $this->installPos();
        $session = $this->openSession();
        $method = \Modules\Pos\Models\PosPaymentMethod::query()->create([
            'name' => 'Cash', 'kind' => 'cash', 'active' => true, 'sequence' => 1,
        ]);
        $product = PosProduct::query()->create(['name' => 'Pepsi', 'price' => 0.45, 'tax_rate' => 0]);

        // Lock the clock so the rendered time is deterministic.
        \Illuminate\Support\Carbon::setTestNow('2026-05-24 18:27:00');

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id)
            ->call('startPayment')
            ->set('countryCode', '+973')
            ->set('localPhone', '33 123 456')
            ->set('paymentMethodId', $method->id)
            ->set('tendered', '0.45')
            ->call('addPayment')
            ->call('validateOrder')
            // Phone shown with the international "+" prefix.
            ->assertSee('Phone: +97333123456')
            // 12-hour clock, not "18:27".
            ->assertSee('6:27 PM')
            ->assertDontSee('18:27');

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_receipt_panel_omits_phone_when_walk_in(): void
    {
        // No phone captured → no "Phone:" line. Layout stays clean for
        // the walk-in case (the most common path).
        $this->installPos();
        $session = $this->openSession();
        $method = \Modules\Pos\Models\PosPaymentMethod::query()->create([
            'name' => 'Cash', 'kind' => 'cash', 'active' => true, 'sequence' => 1,
        ]);
        $product = PosProduct::query()->create(['name' => 'Water', 'price' => 0.30, 'tax_rate' => 0]);

        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->call('addProduct', $product->id)
            ->call('startPayment')
            ->set('paymentMethodId', $method->id)
            ->set('tendered', '0.30')
            ->call('addPayment')
            ->call('validateOrder')
            ->assertDontSee('Phone:');
    }

    public function test_product_form_does_not_redirect_after_save(): void
    {
        // Auto-save persists silently on every keystroke — redirecting
        // mid-typing to the products list would be jarring. The PosProductForm
        // no longer listens for `record-saved` (was a manual-save-button
        // legacy when there was a single explicit save point). Verifies the
        // form host stays on the page for the auto-save lifecycle.
        $this->installPos();
        $product = PosProduct::query()->create(['name' => 'Shisha', 'price' => 20, 'tax_rate' => 0]);

        Livewire::test(PosProductForm::class, ['id' => $product->id])
            ->assertNoRedirect();
    }

    public function test_product_form_saves_with_blank_numeric_fields(): void
    {
        $this->installPos();

        // Leaving Price / Tax % blank must not violate the NOT NULL
        // numeric columns — the engine coerces blank numbers to 0.
        Livewire::test(FormView::class, ['model' => PosProduct::class, 'modelKey' => 'pos.product'])
            ->set('form.name', 'Coal')
            ->call('save');

        $product = PosProduct::query()->where('name->en', 'Coal')->sole();
        $this->assertEqualsWithDelta(0.0, $product->price, 0.001);
        $this->assertEqualsWithDelta(0.0, $product->tax_rate, 0.001);
        $this->assertEqualsWithDelta(0.0, $product->stock_on_hand, 0.001);
    }

    public function test_new_product_form_defaults_active_to_true(): void
    {
        // A brand-new PosProduct should land in the form with the Active
        // checkbox already ticked, matching the DB column default. Without
        // the model-level `$attributes['active'] => true`, the in-memory
        // record reads `null` for unset attributes, the engine coerces it
        // to `false` on save, and a fresh product hides itself from the
        // POS terminal until the cashier re-ticks the box.
        $this->installPos();

        $component = Livewire::test(FormView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ]);

        $component->assertSet('form.active', true);

        $component->set('form.name', 'Default-Active Widget')->call('save');

        $product = PosProduct::query()->where('name->en', 'Default-Active Widget')->sole();
        $this->assertTrue($product->active);
    }

    public function test_home_offers_resume_when_the_register_is_open(): void
    {
        $this->installPos();
        $this->seed(PosSeeder::class);

        // Closed → "Open session".
        Livewire::test(PosHome::class)->assertOk()->assertSee('Open session');

        $this->openSession();

        // Open → globally "Resume selling" (any authorised user).
        Livewire::test(PosHome::class)
            ->assertOk()
            ->assertSee('Register is open')
            ->assertSee('Resume selling');
    }

    public function test_terminal_heartbeat_registers_a_participant_shown_on_manage(): void
    {
        $this->installPos();
        $this->seed(AuthSeeder::class);
        $this->seed(PosSeeder::class);

        $session = $this->openSession();
        $sales = User::query()->where('email', 'sales@example.com')->sole();

        // Opening the terminal beats a heartbeat for the shared session.
        $this->actingAs($sales);
        Livewire::test(PosTerminal::class, ['session' => $session->id])->assertOk();

        $this->assertDatabaseHas('pos_session_participants', [
            'pos_session_id' => $session->id,
            'user_id' => $sales->id,
        ]);

        // The Manage view lists who is live on the shared register.
        $this->actingAs(User::query()->where('email', 'admin@example.com')->sole());
        Livewire::test(PosSessionPage::class, ['id' => $session->id])
            ->assertOk()
            ->assertSee('Active cashiers')
            ->assertSee($sales->name);
    }

    public function test_close_register_is_manager_only(): void
    {
        $this->installPos();
        $this->seed(AuthSeeder::class);
        $this->seed(PosSeeder::class);

        $session = $this->openSession();

        // Cashier has pos.session write but is NOT a manager → blocked.
        $this->actingAs(User::query()->where('email', 'sales@example.com')->sole());
        Livewire::test(PosSessionPage::class, ['id' => $session->id])
            ->set('countedCash', '0')
            ->call('closeSession')
            ->assertForbidden();
        $this->assertSame(SessionState::Opened, $session->refresh()->state);

        // Manager (admin) finalises the single global session.
        $this->actingAs(User::query()->where('email', 'admin@example.com')->sole());
        Livewire::test(PosSessionPage::class, ['id' => $session->id])
            ->set('countedCash', '0')
            ->call('closeSession');
        $this->assertSame(SessionState::Closed, $session->refresh()->state);
    }

    public function test_install_registers_pos_category_and_hierarchy_columns(): void
    {
        $this->installPos();

        $this->assertContains(
            'pos.category',
            IrModel::query()->where('module', 'pos')->pluck('model')->all(),
        );
        foreach (['parent_id', 'slug', 'image'] as $col) {
            $this->assertTrue(Schema::hasColumn('pos_categories', $col), "missing {$col}");
        }
    }

    public function test_category_subtree_ids_and_cycle_guard(): void
    {
        $this->installPos();

        $drinks = PosCategory::query()->create(['name' => 'Drinks']);
        $hot = PosCategory::query()->create(['name' => 'Hot', 'parent_id' => $drinks->id]);
        $espresso = PosCategory::query()->create(['name' => 'Espresso', 'parent_id' => $hot->id]);

        $this->assertEqualsCanonicalizing(
            [$drinks->id, $hot->id, $espresso->id],
            $drinks->subtreeIds(),
        );
        $this->assertSame([$espresso->id], $espresso->subtreeIds());
        $this->assertSame($drinks->id, $hot->parent?->id);

        // Cycle guard: parenting Drinks under its own descendant is rejected.
        $drinks->parent_id = $espresso->id;
        $drinks->save();
        $this->assertNull($drinks->refresh()->parent_id);
    }

    public function test_slug_is_auto_generated_and_unique(): void
    {
        $this->installPos();

        $a = PosCategory::query()->create(['name' => 'Cold Drinks']);
        $b = PosCategory::query()->create(['name' => 'Cold Drinks']);

        $this->assertSame('cold-drinks', $a->slug);
        $this->assertSame('cold-drinks-2', $b->slug);
    }

    public function test_product_form_has_a_dynamic_category_select(): void
    {
        $this->installPos();
        $beverages = PosCategory::query()->create(['name' => 'Beverages']);

        // The engine FormView resolves select options from the model.
        Livewire::test(FormView::class, ['model' => PosProduct::class, 'modelKey' => 'pos.product'])
            ->assertOk()
            ->assertSee('Beverages')
            ->set('form.name', 'Mocha')
            ->set('form.pos_category_id', (string) $beverages->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            $beverages->id,
            PosProduct::query()->where('name->en', 'Mocha')->sole()->pos_category_id,
        );
    }

    public function test_category_crud_through_the_engine(): void
    {
        $this->installPos();

        Livewire::test(PosCategories::class)->assertOk()->assertSee('POS Categories');

        Livewire::test(FormView::class, ['model' => PosCategory::class, 'modelKey' => 'pos.category'])
            ->set('form.name', 'Snacks')
            ->call('save')
            ->assertHasNoErrors();

        // `name` is translatable JSON now — `assertDatabaseHas('name' =>
        // 'Snacks')` looks for the raw envelope and misses. Round-trip
        // through the model instead so Spatie's locale read applies.
        $cat = PosCategory::query()->where('slug', 'snacks')->sole();
        $this->assertSame('Snacks', $cat->name);
    }

    public function test_terminal_filters_products_by_category_subtree(): void
    {
        $this->installPos();
        $this->seed(PosSeeder::class);

        $drinks = PosCategory::query()->create(['name' => 'Drinks']);
        $hot = PosCategory::query()->create(['name' => 'Hot', 'parent_id' => $drinks->id]);

        PosProduct::query()->create(['name' => 'Latte', 'price' => 5, 'tax_rate' => 0, 'pos_category_id' => $hot->id]);
        $other = PosCategory::query()->create(['name' => 'Snacks']);
        PosProduct::query()->create(['name' => 'Crisps', 'price' => 2, 'tax_rate' => 0, 'pos_category_id' => $other->id]);

        $session = $this->openSession();

        // Selecting the PARENT shows the whole subtree (Latte lives in Hot).
        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->set('categoryId', $drinks->id)
            ->assertSee('Latte')
            ->assertDontSee('Crisps');

        // An unrelated branch excludes it.
        Livewire::test(PosTerminal::class, ['session' => $session->id])
            ->set('categoryId', $other->id)
            ->assertSee('Crisps')
            ->assertDontSee('Latte');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Phase 7+: cost_price + profit accessor + product import
    // ─────────────────────────────────────────────────────────────────────

    public function test_profit_accessor_is_sale_price_minus_cost_price(): void
    {
        $this->installPos();
        $p = PosProduct::query()->create([
            'name' => 'Latte', 'price' => 4.5, 'cost_price' => 1.2, 'tax_rate' => 0,
        ]);
        $this->assertEqualsWithDelta(3.3, $p->profit, 0.001);

        // Default cost (0) → profit equals sale price.
        $p2 = PosProduct::query()->create(['name' => 'Water', 'price' => 1.0, 'tax_rate' => 0]);
        $this->assertEqualsWithDelta(1.0, $p2->profit, 0.001);
    }

    private function writeCsv(string $name, string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pos-import-') . '-' . $name;
        file_put_contents($path, $body);

        return $path;
    }

    public function test_importer_resolves_category_by_name_and_creates_missing_ones(): void
    {
        // Three rows, three category states:
        //   - "Hot Drinks" exists → product linked to that id
        //   - "Smoothies" is new   → category auto-created, product linked
        //   - empty Category cell  → product stays uncategorised
        // Plus reuse: a 4th row also using "Smoothies" must NOT create a
        // duplicate category (firstOrCreate semantics).
        $this->installPos();

        $existing = \Modules\Pos\Models\PosCategory::query()->create(['name' => 'Hot Drinks']);

        $csv = "Name,Category,Barcode,Sale Price\n"
             . "Espresso,Hot Drinks,A1,3.50\n"
             . "Mango,Smoothies,B1,4.00\n"
             . "Loose Tea,,C1,2.00\n"
             . "Banana,Smoothies,D1,4.00\n";

        $path = $this->writeCsv('cats.csv', $csv);
        $report = app(PosProductImporter::class)->parse($path);

        $this->assertEmpty($report->fileErrors);
        $this->assertSame('Hot Drinks', $report->rows[0]->categoryName);
        $this->assertSame('Smoothies', $report->rows[1]->categoryName);
        $this->assertNull($report->rows[2]->categoryName);

        app(PosProductImporter::class)->apply($report);

        $espresso = PosProduct::query()->where('barcode', 'A1')->sole();
        $this->assertSame($existing->id, $espresso->pos_category_id);

        $mango = PosProduct::query()->where('barcode', 'B1')->sole();
        // Translatable column lookup — `name` is JSON now, plain string
        // match misses; use the locale-pathed JSON accessor.
        $smoothies = \Modules\Pos\Models\PosCategory::query()->where('name->en', 'Smoothies')->sole();
        $this->assertSame($smoothies->id, $mango->pos_category_id);

        // Duplicate "Smoothies" row attached to the SAME category id.
        $banana = PosProduct::query()->where('barcode', 'D1')->sole();
        $this->assertSame($smoothies->id, $banana->pos_category_id);

        // Empty Category cell → null FK.
        $tea = PosProduct::query()->where('barcode', 'C1')->sole();
        $this->assertNull($tea->pos_category_id);

        // Exactly one new category created (Smoothies), not two.
        $this->assertSame(1, \Modules\Pos\Models\PosCategory::query()->where('name->en', 'Smoothies')->count());
    }

    public function test_importer_leaves_category_alone_on_update_when_cell_is_blank(): void
    {
        // Update path: a blank Category cell must NOT clear an existing
        // product's category. Same contract as price/cost — blank = keep.
        $this->installPos();
        $cat = \Modules\Pos\Models\PosCategory::query()->create(['name' => 'Drinks']);
        PosProduct::query()->create([
            'name' => 'Old', 'barcode' => 'X1', 'price' => 1, 'cost_price' => 0, 'tax_rate' => 0,
            'pos_category_id' => $cat->id,
        ]);

        $csv = "Name,Category,Barcode,Sale Price\n"
             . "Renamed,,X1,2.00\n";

        $report = app(PosProductImporter::class)->parse($this->writeCsv('blank-cat.csv', $csv));
        app(PosProductImporter::class)->apply($report);

        $updated = PosProduct::query()->where('barcode', 'X1')->sole();
        $this->assertSame($cat->id, $updated->pos_category_id);
    }

    public function test_importer_creates_and_updates_products_by_barcode(): void
    {
        $this->installPos();
        // Existing product to be updated by barcode match.
        PosProduct::query()->create([
            'name' => 'Old Tea', 'barcode' => '1111', 'price' => 5, 'cost_price' => 1, 'tax_rate' => 5,
        ]);

        $csv = "Name,Barcode,Sale Price,Cost Price,Tax %\n"
             . "New Coffee,2222,3.50,0.90,15\n"
             . "Updated Tea,1111,6.00,1.20,5\n";

        $path = $this->writeCsv('mix.csv', $csv);
        $report = app(PosProductImporter::class)->parse($path);

        $this->assertEmpty($report->fileErrors);
        $this->assertCount(2, $report->rows);
        $this->assertSame('create', $report->rows[0]->action);
        $this->assertSame('update', $report->rows[1]->action);

        $report = app(PosProductImporter::class)->apply($report);
        $this->assertSame(1, $report->createdCount);
        $this->assertSame(1, $report->updatedCount);

        $new = PosProduct::query()->where('barcode', '2222')->sole();
        $this->assertEqualsWithDelta(3.50, $new->price, 0.001);
        $this->assertEqualsWithDelta(0.90, $new->cost_price, 0.001);
        $this->assertEqualsWithDelta(2.60, $new->profit, 0.001);

        $updated = PosProduct::query()->where('barcode', '1111')->sole();
        $this->assertSame('Updated Tea', $updated->name);
        $this->assertEqualsWithDelta(6.00, $updated->price, 0.001);
        $this->assertEqualsWithDelta(1.20, $updated->cost_price, 0.001);
    }

    public function test_importer_skips_rows_with_missing_name_or_invalid_price(): void
    {
        $this->installPos();

        $csv = "Name,Barcode,Sale Price,Cost Price,Tax %\n"
             . ",3333,2.00,1.00,0\n"      // missing Name → skip
             . "Bad Price,4444,abc,0,0\n" // non-numeric Sale Price → skip
             . "Good,5555,2.50,1.00,0\n";

        $path = $this->writeCsv('bad.csv', $csv);
        $report = app(PosProductImporter::class)->parse($path);

        $this->assertCount(3, $report->rows);
        $this->assertSame(2, $report->skippedCount);
        $this->assertSame(1, $report->validRowCount());

        app(PosProductImporter::class)->apply($report);
        $this->assertSame(1, PosProduct::query()->count());
        $this->assertSame('Good', PosProduct::query()->sole()->name);
    }

    public function test_importer_reports_fatal_error_when_required_columns_missing(): void
    {
        $this->installPos();
        $path = $this->writeCsv('bad-headers.csv', "Title,Barcode\nFoo,1\n");
        $report = app(PosProductImporter::class)->parse($path);

        $this->assertTrue($report->isFatal());
        $this->assertSame([], $report->rows);
        $this->assertContains("Missing required column: 'Name'.", $report->fileErrors);
        $this->assertContains("Missing required column: 'Sale Price'.", $report->fileErrors);
    }

    public function test_preview_caches_report_and_renders_arabic_rows(): void
    {
        // Regression: the ImportReport DTO used to live as a public
        // Livewire property. Livewire 3's nested-array marker format
        // (`{"s":"arr"}`) wrapping each associative entry on the wire
        // mangled rows during the rehydrate, so a 48-row preview
        // collapsed to 2 garbage rows by the time `commit()` ran —
        // and the click "did nothing". We now park the report in the
        // cache and only keep its key on the component; sidesteps the
        // entire Livewire serialisation problem.
        $this->installPos();
        $this->seed(AuthSeeder::class);
        $this->actingAs(User::query()->where('email', 'admin@example.com')->sole());

        $csv = "Name,Sale Price,Cost\n"
             . "افوكادو,1.2,0\n"
             . "موز,0.8,0\n"
             . "ليمون نعناع,0.8,0\n";

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('arabic.csv', $csv);

        $component = Livewire::test(\Modules\Pos\Livewire\PosProductImport::class)
            ->set('file', $file)
            ->call('preview')
            ->assertSet('stage', 'preview')
            ->assertHasNoErrors()
            ->assertSee('افوكادو')
            ->assertSee('موز')
            ->assertSee('ليمون نعناع');

        $report = $component->instance()->loadReport();
        $this->assertNotNull($report);
        $this->assertCount(3, $report->rows);
        $this->assertSame('افوكادو', $report->rows[0]->name);
        $this->assertSame('موز', $report->rows[1]->name);
        $this->assertSame('ليمون نعناع', $report->rows[2]->name);
    }

    public function test_confirm_import_uses_cached_preview_when_uploaded_file_is_gone(): void
    {
        // Reproduces the prod-only "click Confirm, nothing happens" bug:
        // Hostinger's tmp janitor cleans Livewire's temp uploads between
        // requests. `commit()` used to silently return when the file
        // wasn't there. Now it falls back to the cached preview report
        // and the import goes through.
        $this->installPos();
        $this->seed(AuthSeeder::class);
        $this->actingAs(User::query()->where('email', 'admin@example.com')->sole());

        $csv = "Name,Sale Price,Cost\n"
             . "افوكادو,1.2,0\n"
             . "موز,0.8,0\n";

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('arabic.csv', $csv);

        $c = Livewire::test(\Modules\Pos\Livewire\PosProductImport::class)
            ->set('file', $file)
            ->call('preview')
            ->assertSet('stage', 'preview');

        // Simulate the file vanishing between preview and commit.
        $c->set('file', null);

        $c->call('confirmImport')
            ->assertSet('stage', 'done');

        // Arabic names route to the AR locale (per importer's locale
        // detection); the EN pill stays empty for these rows.
        $this->assertSame(2, PosProduct::query()->count());
        $this->assertTrue(PosProduct::query()->where('name->ar', 'افوكادو')->exists());
        $this->assertTrue(PosProduct::query()->where('name->ar', 'موز')->exists());
    }

    public function test_importer_routes_arabic_names_to_ar_locale_and_english_to_en(): void
    {
        // Smart locale detection on import: an Arabic-script value lands
        // under the AR pill, an English-script value under EN — same CSV,
        // mixed rows, no extra columns needed in the template.
        $this->installPos();

        $csv = "Name,Sale Price\n"
             . "Espresso,3.0\n"             // English → en
             . "افوكادو,1.2\n"              // Arabic → ar
             . "موز,0.8\n";                 // Arabic → ar

        $path = $this->writeCsv('mixed-locale.csv', $csv);
        $report = app(PosProductImporter::class)->parse($path);
        app(PosProductImporter::class)->apply($report);

        $espresso = PosProduct::query()->where('name->en', 'Espresso')->sole();
        $this->assertSame(['en' => 'Espresso'], $espresso->getTranslations('name'));
        $this->assertArrayNotHasKey('ar', $espresso->getTranslations('name'));

        $avocado = PosProduct::query()->where('name->ar', 'افوكادو')->sole();
        $this->assertSame(['ar' => 'افوكادو'], $avocado->getTranslations('name'));
        $this->assertArrayNotHasKey('en', $avocado->getTranslations('name'));
    }

    public function test_importer_update_preserves_the_other_locale_translation(): void
    {
        // A product that already has BOTH locales should keep the
        // opposite-locale value when an import overwrites only one side.
        $this->installPos();

        $product = PosProduct::query()->create(['name' => 'Espresso', 'barcode' => '9999', 'price' => 3.0, 'tax_rate' => 0]);
        $product->setTranslation('name', 'ar', 'إسبريسو')->save();

        // Import an Arabic-only row matched on the same barcode — should
        // touch ONLY the AR translation, not wipe the EN one.
        $csv = "Name,Barcode,Sale Price\nإسبريسو محدث,9999,3.5\n";
        $path = $this->writeCsv('update-ar-only.csv', $csv);
        $report = app(PosProductImporter::class)->parse($path);
        app(PosProductImporter::class)->apply($report);

        $fresh = $product->fresh();
        $this->assertSame('Espresso', $fresh->getTranslation('name', 'en'));     // preserved
        $this->assertSame('إسبريسو محدث', $fresh->getTranslation('name', 'ar')); // overwritten
        $this->assertEqualsWithDelta(3.5, $fresh->price, 0.001);                // updated
    }

    public function test_confirm_import_with_no_file_and_no_cached_report_surfaces_error(): void
    {
        // The other side of the same defence: if BOTH the file and the
        // cached preview are missing, confirmImport must surface a real
        // error rather than dropping the user on a button that does
        // nothing.
        $this->installPos();
        $this->seed(AuthSeeder::class);
        $this->actingAs(User::query()->where('email', 'admin@example.com')->sole());

        Livewire::test(\Modules\Pos\Livewire\PosProductImport::class)
            ->set('file', null)
            ->call('confirmImport')
            ->assertSet('stage', 'preview')
            ->assertSet('file', null)
            ->assertSee('no longer available');

        $this->assertSame(0, PosProduct::query()->count());
    }

    public function test_importer_flags_duplicate_barcodes_within_the_same_file(): void
    {
        $this->installPos();
        $csv = "Name,Barcode,Sale Price\nA,7777,1.00\nB,7777,2.00\n";
        $path = $this->writeCsv('dup.csv', $csv);

        $report = app(PosProductImporter::class)->parse($path);

        // First occurrence is valid; the second is skipped with a duplicate-barcode error.
        $this->assertSame('create', $report->rows[0]->action);
        $this->assertSame('skip', $report->rows[1]->action);
        $this->assertNotEmpty($report->rows[1]->errors);
    }

    public function test_import_template_controller_streams_csv_with_expected_headers(): void
    {
        $this->installPos();

        // Module routes only register on the *next* boot after install, so
        // a $this->get(...) here 404s. Per memory: instantiate the
        // invokable controller directly — security logic stays covered.
        $response = (new \Modules\Pos\Http\Controllers\PosProductImportTemplateController())();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $body = ltrim((string) $response->getContent(), "\xEF\xBB\xBF");
        $this->assertStringStartsWith('Name,Category,Barcode,"Sale Price","Cost Price","Tax %"', $body);
    }

    public function test_export_controller_streams_csv_of_all_products(): void
    {
        $this->installPos();

        PosProduct::query()->create([
            'name' => 'Espresso', 'price' => 2.50, 'tax_rate' => 10.0,
            'cost_price' => 0.80, 'barcode' => '900001',
        ]);
        PosProduct::query()->create([
            'name' => 'Cappuccino', 'price' => 3.20, 'tax_rate' => 10.0,
            'cost_price' => 1.10, 'barcode' => '900002',
        ]);

        $response = (new \Modules\Pos\Http\Controllers\PosProductExportController())();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));

        $body = ltrim((string) $response->getContent(), "\xEF\xBB\xBF");
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $body)), strlen(...)));

        // Header matches the importer's canonical column set so an export
        // can be edited and re-imported without manual reshaping.
        $this->assertSame('Name,Category,Barcode,"Sale Price","Cost Price","Tax %"', $lines[0]);

        // Two product rows, sorted alphabetically by name for stable diffs.
        $this->assertCount(3, $lines); // 1 header + 2 products
        // Category column is empty (these products were created without one).
        $this->assertSame('Cappuccino,,900002,3.20,1.10,10.00', $lines[1]);
        $this->assertSame('Espresso,,900001,2.50,0.80,10.00', $lines[2]);
    }

    public function test_export_emits_only_header_when_catalogue_is_empty(): void
    {
        $this->installPos();

        $response = (new \Modules\Pos\Http\Controllers\PosProductExportController())();

        $body = ltrim((string) $response->getContent(), "\xEF\xBB\xBF");
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $body)), strlen(...)));

        $this->assertCount(1, $lines);
        $this->assertSame('Name,Category,Barcode,"Sale Price","Cost Price","Tax %"', $lines[0]);
    }

    public function test_export_blanks_a_missing_barcode_rather_than_writing_null(): void
    {
        $this->installPos();
        PosProduct::query()->create([
            'name' => 'Mystery Drink', 'price' => 5.00, 'tax_rate' => 0,
            'cost_price' => 0, 'barcode' => null,
        ]);

        $response = (new \Modules\Pos\Http\Controllers\PosProductExportController())();
        $body = ltrim((string) $response->getContent(), "\xEF\xBB\xBF");

        // The barcode column is empty (no "null" string leaking into the CSV).
        $this->assertStringContainsString('"Mystery Drink",,,5.00,0.00,0.00', $body);
    }

    public function test_export_requires_pos_product_read(): void
    {
        $this->installPos();

        // Plain authenticated user with is_admin=false and no group
        // memberships. AccessControl denies-by-default: no group → no
        // ModelAccess row matches → AuthorizationException.
        $stranger = User::factory()->create(['is_admin' => false]);
        $this->actingAs($stranger);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        (new \Modules\Pos\Http\Controllers\PosProductExportController())();
    }
}
