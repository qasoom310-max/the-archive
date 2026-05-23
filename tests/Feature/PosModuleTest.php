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
use App\Models\Mail\MessageType;
use App\Livewire\Views\FormView;
use App\Livewire\Views\ListView;
use App\Models\User;
use Database\Seeders\AuthSeeder;
use Database\Seeders\PosSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
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
        return [
            'jpg'  => ['photo.jpg',  'image/jpeg', 'Burger'],
            'jpeg' => ['photo.jpeg', 'image/jpeg', 'Pizza'],
            'png'  => ['photo.png',  'image/png',  'Salad'],
            'gif'  => ['photo.gif',  'image/gif',  'Soup'],
            'webp' => ['photo.webp', 'image/webp', 'حليب جنزبيل'], // ← user's actual case
            'bmp'  => ['photo.bmp',  'image/bmp',  'Bread'],
            'svg'  => ['photo.svg',  'image/svg+xml', 'Cake'],
            'avif' => ['photo.avif', 'image/avif', 'Pepsi'],
            'heic' => ['photo.heic', 'image/heic', 'Coffee'],
            'heif' => ['photo.heif', 'image/heif', 'Tea'],
        ];
    }

    /**
     * @dataProvider imageFormatProvider
     */
    public function test_product_form_accepts_every_claimed_image_format(string $filename, string $mime, string $productName): void
    {
        // Exhaustive coverage of every format the FormView claims to accept.
        // Includes the WebP + Arabic-product-name combo that reported a 500
        // — pinning every format-row prevents regressions like the AVIF one,
        // where the validation rule was correct but Livewire's preview path
        // threw on an unrelated layer.
        $this->installPos();
        Storage::fake('public');

        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        Livewire::test(FormView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
        ])
            ->set('form.name', $productName)
            ->set('form.price', 1.0)
            ->set('uploads.image_path', UploadedFile::fake()->create($filename, 8, $mime))
            ->assertHasNoErrors()
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('record-saved');

        $product = PosProduct::query()->where('name->en', $productName)->sole();
        $this->assertNotNull($product->image_path);
        // Symfony normalises `.jpeg` to `.jpg` at storage time — accept both.
        $expected = $extension === 'jpeg' ? 'jpg' : $extension;
        $this->assertStringEndsWith('.' . $expected, $product->image_path);
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

    public function test_saving_a_product_redirects_to_the_products_list(): void
    {
        $this->installPos();
        $product = PosProduct::query()->create(['name' => 'Shisha', 'price' => 20, 'tax_rate' => 0]);

        // The engine FormView dispatches `record-saved`; the product form
        // redirects to the POS products list so the user sees the saved row
        // (the layout's flash toast carries the visible "Saved." confirmation).
        Livewire::test(PosProductForm::class)
            ->call('onSaved', $product->id)
            ->assertRedirect('/app/pos/product');
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

        $this->assertDatabaseHas('pos_categories', ['name' => 'Snacks', 'slug' => 'snacks']);
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
        $this->assertStringStartsWith('Name,Barcode,"Sale Price","Cost Price","Tax %"', $body);
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
        $this->assertSame('Name,Barcode,"Sale Price","Cost Price","Tax %"', $lines[0]);

        // Two product rows, sorted alphabetically by name for stable diffs.
        $this->assertCount(3, $lines); // 1 header + 2 products
        $this->assertSame('Cappuccino,900002,3.20,1.10,10.00', $lines[1]);
        $this->assertSame('Espresso,900001,2.50,0.80,10.00', $lines[2]);
    }

    public function test_export_emits_only_header_when_catalogue_is_empty(): void
    {
        $this->installPos();

        $response = (new \Modules\Pos\Http\Controllers\PosProductExportController())();

        $body = ltrim((string) $response->getContent(), "\xEF\xBB\xBF");
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $body)), strlen(...)));

        $this->assertCount(1, $lines);
        $this->assertSame('Name,Barcode,"Sale Price","Cost Price","Tax %"', $lines[0]);
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
        $this->assertStringContainsString('"Mystery Drink",,5.00,0.00,0.00', $body);
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
