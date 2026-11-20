<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Business\Feature;
use App\Erp\Business\Features;
use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Pos\Enums\FulfillmentStatus;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SalesChannel;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Models\PosCategory;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosSession;
use Modules\Storefront\Livewire\StorefrontOrders;
use Modules\Storefront\Livewire\StorefrontProducts;
use Modules\Storefront\Livewire\StorefrontSettings;
use Modules\Storefront\Models\StorefrontConfiguration;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontProductDetail;
use Modules\Storefront\Support\StoreSignature;
use Tests\TestCase;

/**
 * The shop website's door into the ERP (/storefront/v1/{ws}/…): signed with
 * the shared secret or refused, shows only products fit for the website,
 * prices every order itself, and files orders in an inbox that touches
 * nothing until staff accept one into the register.
 */
final class StorefrontApiTest extends TestCase
{
    use DatabaseMigrations;

    private const SECRET = 'test-secret-0123456789abcdef';

    private ?PosCategory $category = null;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('pos');
        app(ModuleManager::class)->install('storefront');

        // Module routes are wired at boot; the module was installed after it.
        if (! Route::has('storefront.settings')) {
            Route::middleware('web')->group(base_path('Modules/Storefront/routes/web.php'));
        }

        Features::setOverrides([Feature::RemoteSales->value => true]);
        StorefrontConfiguration::query()->create([
            'enabled' => true,
            'shared_secret' => self::SECRET,
            'whatsapp_number' => '+973 3300 0000',
            'delivery_charge' => 1.5,
            'free_delivery_over' => 30,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function product(string $name, float $price, float $stock = 10, array $attributes = []): PosProduct
    {
        $this->category ??= PosCategory::query()->create(['name' => '100ml perfumes']);

        return PosProduct::query()->create([
            'name' => $name,
            'price' => $price,
            'tax_rate' => 0,
            'active' => true,
            'publish_online' => true,
            'stock_on_hand' => $stock,
            'pos_category_id' => $this->category->id,
            'image_path' => 'pos_products/' . strtolower($name) . '.jpg',
            ...$attributes,
        ]);
    }

    /** @return array<string, string> */
    private function signed(string $method, string $path, string $body = '', ?string $secret = null): array
    {
        $timestamp = (string) time();

        return [
            'HTTP_' . strtoupper(str_replace('-', '_', StoreSignature::TIMESTAMP_HEADER)) => $timestamp,
            'HTTP_' . strtoupper(str_replace('-', '_', StoreSignature::SIGNATURE_HEADER)) => StoreSignature::sign($method, $path, $body, $timestamp, $secret ?? self::SECRET),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];
    }

    /** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function getCatalog(?string $secret = null): TestResponse
    {
        return $this->call('GET', '/storefront/v1/0/catalog', [], [], [], $this->signed('GET', '/storefront/v1/0/catalog', '', $secret));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function postOrder(array $payload): TestResponse
    {
        $body = (string) json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/storefront/v1/0/orders', [], [], [], $this->signed('POST', '/storefront/v1/0/orders', $body), $body);
    }

    /**
     * @param  list<array{product_id: int, qty: int}>  $lines
     * @return array<string, mixed>
     */
    private function order(array $lines, string $reference = 'K-1001'): array
    {
        return [
            'reference' => $reference,
            'customer' => ['name' => 'Test Customer', 'phone' => '3311 2233', 'address' => "Road 1, Block 2\nManama", 'notes' => 'Call first'],
            'lines' => $lines,
        ];
    }

    private function openSession(): PosSession
    {
        return PosSession::query()->create([
            'reference' => 'POS-S/0001',
            'state' => SessionState::Opened,
            'opening_cash' => 0.0,
            'opened_at' => now(),
        ]);
    }

    public function test_an_unsigned_or_wrongly_signed_request_is_refused(): void
    {
        $this->product('Rich', 20);

        $this->getJson('/storefront/v1/0/catalog')->assertStatus(401);
        $this->getCatalog('the-wrong-secret')->assertStatus(401);
    }

    public function test_a_signature_for_one_path_does_not_open_another(): void
    {
        $headers = $this->signed('GET', '/storefront/v1/0/catalog');

        $this->call('GET', '/storefront/v1/0/orders/K-1', [], [], [], $headers)->assertStatus(401);
    }

    public function test_a_switched_off_store_answers_nothing(): void
    {
        StorefrontConfiguration::query()->update(['enabled' => false]);

        $this->getCatalog()->assertStatus(403);
    }

    public function test_an_unknown_workspace_never_falls_back_to_main(): void
    {
        $this->call('GET', '/storefront/v1/999/catalog', [], [], [], $this->signed('GET', '/storefront/v1/999/catalog'))
            ->assertStatus(404);
    }

    public function test_the_catalog_lists_only_products_fit_for_the_website_and_hides_costs(): void
    {
        $rich = $this->product('Rich', 20, 4, ['cost_price' => 6.5, 'bottle_size_ml' => 100]);
        $rich->setTranslation('name', 'ar', 'ريتش')->save();
        $this->product('Hidden', 20, 4, ['publish_online' => false]);
        $this->product('NoPhoto', 20, 4, ['image_path' => null]);
        $this->product('Inactive', 20, 4, ['active' => false]);
        $this->product('SoldOut', 20, 0);

        StorefrontProductDetail::query()->create([
            'pos_product_id' => $rich->id,
            'description' => ['en' => 'Warm and sweet.', 'ar' => 'دافئ وحلو.'],
            'notes_top' => ['en' => 'Saffron, Cardamom', 'ar' => 'زعفران، هيل'],
            'featured' => true,
        ]);

        $response = $this->getCatalog()->assertOk();

        $names = array_column(array_column($response->json('products'), 'name'), 'en');
        $this->assertSame(['Rich', 'SoldOut'], $names);

        $first = $response->json('products.0');
        $this->assertSame(['en' => 'Rich', 'ar' => 'ريتش'], $first['name']);
        $this->assertEquals(20.0, $first['price']);
        $this->assertEquals(100.0, $first['size_ml']);
        $this->assertTrue($first['in_stock']);
        $this->assertTrue($first['featured']);
        $this->assertSame(['Saffron', 'Cardamom'], $first['notes']['top']['en']);
        $this->assertSame(['زعفران', 'هيل'], $first['notes']['top']['ar']);
        $this->assertStringEndsWith('pos_products/rich.jpg', (string) $first['image']);
        $this->assertArrayNotHasKey('cost_price', $first);
        $this->assertArrayNotHasKey('stock_on_hand', $first);
        $this->assertFalse($response->json('products.1.in_stock'));

        $this->assertSame('100ml perfumes', $response->json('categories.0.name.en'));
        $this->assertSame('97333000000', $response->json('store.whatsapp'));
        $this->assertEquals(30.0, $response->json('store.free_delivery_over'));
    }

    public function test_the_catalog_answers_304_when_nothing_changed(): void
    {
        $this->product('Rich', 20);
        $etag = (string) $this->getCatalog()->headers->get('ETag');

        $headers = $this->signed('GET', '/storefront/v1/0/catalog') + ['HTTP_IF_NONE_MATCH' => $etag];
        $this->call('GET', '/storefront/v1/0/catalog', [], [], [], $headers)->assertStatus(304);
    }

    public function test_an_order_is_priced_by_the_erp_and_waits_in_the_inbox_without_touching_stock(): void
    {
        $rich = $this->product('Rich', 12.5, 10);
        $wave = $this->product('Blue Wave', 8, 10);

        $response = $this->postOrder([
            ...$this->order([['product_id' => $rich->id, 'qty' => 1], ['product_id' => $wave->id, 'qty' => 1], ['product_id' => $rich->id, 'qty' => 1]]),
            // Whatever the website claims, the ERP prices it.
            'total' => 0.001,
        ])->assertStatus(201);

        $response->assertJson(['reference' => 'K-1001', 'status' => 'new', 'subtotal' => 33, 'delivery_charge' => 0, 'total' => 33]);

        $inbox = StorefrontOrder::query()->sole();
        $this->assertSame('97333112233', $inbox->customer_phone);
        $this->assertSame(2, $inbox->lines[0]['qty']);
        $this->assertSame(0, PosOrder::query()->count());
        $this->assertEquals(10.0, $rich->fresh()?->stock_on_hand);
    }

    public function test_delivery_is_charged_below_the_free_delivery_amount(): void
    {
        $rich = $this->product('Rich', 20);

        $this->postOrder($this->order([['product_id' => $rich->id, 'qty' => 1]]))
            ->assertStatus(201)
            ->assertJson(['subtotal' => 20, 'delivery_charge' => 1.5, 'total' => 21.5]);
    }

    public function test_the_same_order_sent_twice_is_filed_once(): void
    {
        $rich = $this->product('Rich', 20);

        $this->postOrder($this->order([['product_id' => $rich->id, 'qty' => 1]]))->assertStatus(201);
        $this->postOrder($this->order([['product_id' => $rich->id, 'qty' => 3]]))->assertStatus(200)->assertJson(['total' => 21.5]);

        $this->assertSame(1, StorefrontOrder::query()->count());
    }

    public function test_hidden_out_of_stock_and_unknown_products_are_refused_whole(): void
    {
        $rich = $this->product('Rich', 20, 2);
        $hidden = $this->product('Hidden', 20, 5, ['publish_online' => false]);

        $this->postOrder($this->order([['product_id' => $rich->id, 'qty' => 3]]))
            ->assertStatus(422)->assertJsonPath('errors.unavailable', [$rich->id]);
        $this->postOrder($this->order([['product_id' => $rich->id, 'qty' => 1], ['product_id' => $hidden->id, 'qty' => 1]]))
            ->assertStatus(422)->assertJsonPath('errors.unavailable', [$hidden->id]);
        $this->postOrder($this->order([['product_id' => 99999, 'qty' => 1]]))->assertStatus(422);

        $this->assertSame(0, StorefrontOrder::query()->count());
    }

    public function test_a_malformed_order_is_refused(): void
    {
        $rich = $this->product('Rich', 20);

        $this->postOrder($this->order([['product_id' => $rich->id, 'qty' => 0]]))->assertStatus(422)->assertJsonStructure(['errors' => ['lines']]);
        $this->postOrder($this->order([['product_id' => $rich->id, 'qty' => 21]]))->assertStatus(422);
        $this->postOrder($this->order([['product_id' => (string) $rich->id, 'qty' => 1]]))->assertStatus(422);
        $this->postOrder([...$this->order([['product_id' => $rich->id, 'qty' => 1]]), 'reference' => 'bad ref!'])->assertStatus(422);
        $this->postOrder([...$this->order([['product_id' => $rich->id, 'qty' => 1]]), 'customer' => ['name' => '', 'phone' => '12', 'address' => '']])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['customer.name', 'customer.phone', 'customer.address']]);
    }

    public function test_order_status_can_be_read_back(): void
    {
        $rich = $this->product('Rich', 20);
        $this->postOrder($this->order([['product_id' => $rich->id, 'qty' => 1]], 'K-77'));

        $this->call('GET', '/storefront/v1/0/orders/K-77', [], [], [], $this->signed('GET', '/storefront/v1/0/orders/K-77'))
            ->assertOk()->assertJson(['reference' => 'K-77', 'status' => 'new']);
        $this->call('GET', '/storefront/v1/0/orders/K-78', [], [], [], $this->signed('GET', '/storefront/v1/0/orders/K-78'))
            ->assertStatus(404);
    }

    public function test_accepting_makes_a_pay_on_delivery_remote_order_in_the_open_register(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $session = $this->openSession();
        $rich = $this->product('Rich', 20);
        $this->postOrder($this->order([['product_id' => $rich->id, 'qty' => 2]]));
        $inbox = StorefrontOrder::query()->sole();

        Livewire::test(StorefrontOrders::class)->call('accept', $inbox->id)->assertSet('error', '');

        $pos = PosOrder::query()->with('lines')->sole();
        $this->assertSame($session->id, $pos->pos_session_id);
        $this->assertSame(SalesChannel::Remote, $pos->channel);
        $this->assertSame(OrderState::Done, $pos->state);
        $this->assertSame(FulfillmentStatus::New, $pos->fulfillment_status);
        $this->assertEquals(40.0, $pos->total);
        $this->assertEquals(0.0, $pos->paid_total);
        $this->assertSame('97333112233', $pos->customer_phone);
        $this->assertStringContainsString('K-1001', (string) $pos->notes);
        $this->assertSame(2.0, (float) $pos->lines->sole()->qty);

        $inbox->refresh();
        $this->assertSame(StorefrontOrder::STATUS_ACCEPTED, $inbox->status);
        $this->assertSame($pos->id, $inbox->pos_order_id);

        // A second press does nothing more.
        Livewire::test(StorefrontOrders::class)->call('accept', $inbox->id)->assertSet('error', 'This website order has already been dealt with.');
        $this->assertSame(1, PosOrder::query()->count());
    }

    public function test_accepting_with_no_open_register_says_so_and_keeps_the_order(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $rich = $this->product('Rich', 20);
        $this->postOrder($this->order([['product_id' => $rich->id, 'qty' => 1]]));
        $inbox = StorefrontOrder::query()->sole();

        Livewire::test(StorefrontOrders::class)->call('accept', $inbox->id)
            ->assertSet('error', 'Open the register first: an accepted order goes into the open POS session.');

        $this->assertTrue($inbox->fresh()?->isNew());
        $this->assertSame(0, PosOrder::query()->count());
    }

    public function test_rejecting_keeps_a_reason_and_creates_nothing(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $rich = $this->product('Rich', 20);
        $this->postOrder($this->order([['product_id' => $rich->id, 'qty' => 1]]));
        $inbox = StorefrontOrder::query()->sole();

        Livewire::test(StorefrontOrders::class)
            ->call('startReject', $inbox->id)
            ->set('rejectReason', 'Customer did not answer')
            ->call('confirmReject');

        $inbox->refresh();
        $this->assertSame(StorefrontOrder::STATUS_REJECTED, $inbox->status);
        $this->assertSame('Customer did not answer', $inbox->reject_reason);
        $this->assertSame(0, PosOrder::query()->count());
    }

    public function test_website_details_are_saved_per_product(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $rich = $this->product('Rich', 20);

        Livewire::test(StorefrontProducts::class)
            ->call('edit', $rich->id)
            ->set('texts.description.en', 'Warm amber.')
            ->set('texts.notes_base.ar', 'عنبر، مسك')
            ->set('featured', true)
            ->call('save')
            ->assertSet('saved', true);

        $detail = StorefrontProductDetail::query()->sole();
        $this->assertSame('Warm amber.', $detail->text('description', 'en'));
        $this->assertSame(['عنبر', 'مسك'], $detail->notes('notes_base', 'ar'));
        $this->assertTrue($detail->featured);
    }

    public function test_settings_are_for_administrators_and_need_a_secret_before_switching_on(): void
    {
        StorefrontConfiguration::query()->delete();

        $this->actingAs(User::factory()->create(['is_admin' => false]));
        Livewire::test(StorefrontSettings::class)->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Livewire::test(StorefrontSettings::class)
            ->set('enabled', true)
            ->call('save')
            ->assertHasErrors('enabled');

        $component = Livewire::test(StorefrontSettings::class)->call('generateSecret');
        $secret = (string) $component->get('generated');
        $this->assertSame(64, strlen($secret));

        $component->set('enabled', true)->set('deliveryCharge', '2')->call('save')->assertHasNoErrors();

        $config = StorefrontConfiguration::current();
        $this->assertTrue($config->isConfigured());
        $this->assertSame($secret, $config->shared_secret);
        $this->assertEquals(2.0, $config->delivery_charge);
    }

    public function test_a_business_database_serves_its_own_products_with_its_own_secret(): void
    {
        $this->product('Main only', 20);

        $manager = app(\App\Erp\Tenancy\WorkspaceManager::class);
        $manager->ensureMain();
        $owner = User::factory()->create(['is_admin' => true]);
        $kaleem = $manager->provision('Kaleem', $owner, ['pos', 'storefront']);

        $manager->withTenant((string) $kaleem->databasePath(), function (): void {
            $category = PosCategory::query()->create(['name' => '200ml perfumes']);
            PosProduct::query()->create([
                'name' => 'Sapphire', 'price' => 20, 'tax_rate' => 0, 'active' => true, 'publish_online' => true,
                'stock_on_hand' => 3, 'pos_category_id' => $category->id, 'image_path' => 'pos_products/sapphire.jpg',
            ]);
            StorefrontConfiguration::query()->create(['enabled' => true, 'shared_secret' => 'kaleem-secret']);
        });

        $path = '/storefront/v1/' . $kaleem->id . '/catalog';

        // Main's secret does not open Kaleem's catalog.
        $this->call('GET', $path, [], [], [], $this->signed('GET', $path))->assertStatus(401);

        $response = $this->call('GET', $path, [], [], [], $this->signed('GET', $path, '', 'kaleem-secret'))->assertOk();
        $this->assertSame(['Sapphire'], array_column(array_column($response->json('products'), 'name'), 'en'));

        foreach (glob(storage_path('app/workspaces') . '/*.sqlite') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function test_remote_sales_shows_the_website_orders_button_only_where_the_store_is_used(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $rich = $this->product('Rich', 20);
        $this->postOrder($this->order([['product_id' => $rich->id, 'qty' => 1]]));

        Livewire::test(\Modules\Pos\Livewire\RemoteOrders::class)->assertViewHas('websiteNewCount', 1);

        StorefrontOrder::query()->delete();
        StorefrontConfiguration::query()->update(['enabled' => false]);
        Livewire::test(\Modules\Pos\Livewire\RemoteOrders::class)->assertViewHas('websiteNewCount', null);
    }
}
