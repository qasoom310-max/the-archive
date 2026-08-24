<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Pos\Models\PosCategory;
use Modules\Pos\Models\PosProduct;
use Modules\WooCommerce\Jobs\SyncProductToWooCommerce;
use Modules\WooCommerce\Livewire\WooCommerceSettings;
use Modules\WooCommerce\Models\WooCommerceConfiguration;
use Modules\WooCommerce\Models\WooCommerceProductLink;
use Modules\WooCommerce\Providers\WooCommerceServiceProvider;
use Modules\WooCommerce\Services\WooCommerceService;
use Tests\TestCase;

/**
 * Phase A — ERP → WooCommerce product push: per-database encrypted config, a
 * queued upsert job, the product save/delete hooks, and the admin Settings tab.
 */
final class WooCommerceModuleTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function install(): void
    {
        // Pulls pos (its dependency) too.
        app(ModuleManager::class)->install('woocommerce');
    }

    private function configure(): WooCommerceConfiguration
    {
        return WooCommerceConfiguration::query()->create([
            'store_url' => 'https://shop.example.com',
            'consumer_key' => 'ck_live_secret',
            'consumer_secret' => 'cs_live_secret',
            'api_version' => 'wc/v3',
            'enabled' => true,
        ]);
    }

    private function product(array $attributes = []): PosProduct
    {
        return PosProduct::query()->create(array_merge([
            'name' => 'Oud Perfume',
            'price' => 25.0,
            'tax_rate' => 0.0,
            'active' => true,
            'stock_on_hand' => 7,
            'barcode' => 'OUD-001',
        ], $attributes));
    }

    public function test_install_creates_the_config_and_link_tables(): void
    {
        $this->install();

        $this->assertTrue(Schema::hasTable('woocommerce_configuration'));
        $this->assertTrue(Schema::hasTable('woocommerce_product_links'));
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $this->install();

        Livewire::test(WooCommerceSettings::class)
            ->set('storeUrl', 'https://shop.example.com')
            ->set('consumerKey', 'ck_SUPER_SECRET')
            ->set('consumerSecret', 'cs_SUPER_SECRET')
            ->set('enabled', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true)
            ->assertSet('consumerKey', ''); // cleared after save

        $config = WooCommerceConfiguration::current();
        $this->assertTrue($config->isConfigured());
        $this->assertSame('ck_SUPER_SECRET', $config->consumer_key);

        $raw = DB::table('woocommerce_configuration')->value('consumer_key');
        $this->assertIsString($raw);
        $this->assertNotSame('ck_SUPER_SECRET', $raw);
    }

    public function test_enabling_without_credentials_is_rejected(): void
    {
        $this->install();

        Livewire::test(WooCommerceSettings::class)
            ->set('enabled', true)
            ->set('storeUrl', '')
            ->call('save')
            ->assertHasErrors('enabled')
            ->assertSet('saved', false);
    }

    public function test_non_admin_cannot_open_the_settings_tab(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(WooCommerceSettings::class)->assertForbidden();
    }

    public function test_service_queues_a_sync_and_creates_a_link(): void
    {
        $this->install();
        $this->configure();
        $product = $this->product();
        Bus::fake();

        app(WooCommerceService::class)->syncProduct($product);

        Bus::assertDispatched(SyncProductToWooCommerce::class, function (SyncProductToWooCommerce $job) use ($product): bool {
            return $job->posProductId === (int) $product->id && $job->action === 'sync';
        });

        $link = WooCommerceProductLink::query()->where('pos_product_id', $product->id)->first();
        $this->assertNotNull($link);
        $this->assertSame('queued', $link->last_status);
    }

    public function test_service_no_ops_when_not_configured(): void
    {
        $this->install();
        $product = $this->product();
        Bus::fake();

        // No config row → unconfigured.
        app(WooCommerceService::class)->syncProduct($product);

        Bus::assertNothingDispatched();
        $this->assertSame(0, WooCommerceProductLink::query()->count());
    }

    public function test_job_creates_a_remote_product_then_updates_it(): void
    {
        $this->install();
        $this->configure();
        $product = $this->product();

        Http::fake([
            '*/wp-json/wc/v3/products' => Http::response(['id' => 555], 201),
            '*/wp-json/wc/v3/products/555' => Http::response(['id' => 555], 200),
        ]);

        // First run → POST, stores the remote id.
        (new SyncProductToWooCommerce((int) $product->id))->handle(app(WooCommerceService::class));

        $link = WooCommerceProductLink::query()->where('pos_product_id', $product->id)->firstOrFail();
        $this->assertSame(555, $link->woo_id);
        $this->assertSame('synced', $link->last_status);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/wp-json/wc/v3/products')
                && $request['sku'] === 'OUD-001'
                && $request['status'] === 'publish'
                && $request['stock_quantity'] === 7;
        });

        // Second run → PUT to the same remote id (update, not duplicate).
        (new SyncProductToWooCommerce((int) $product->id))->handle(app(WooCommerceService::class));

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/wp-json/wc/v3/products/555'));
    }

    public function test_secondary_gallery_images_push_after_the_primary(): void
    {
        $this->install();
        $this->configure();
        $product = $this->product([
            'image_path' => 'pos_products/primary.webp',
            'gallery_images' => ['pos_products/extra1.webp', 'pos_products/extra2.webp'],
        ]);

        Http::fake(['*/wp-json/wc/v3/products' => Http::response(['id' => 777], 201)]);

        (new SyncProductToWooCommerce((int) $product->id))->handle(app(WooCommerceService::class));

        Http::assertSent(function ($request): bool {
            $images = $request['images'] ?? [];

            // Primary first, then both gallery images, in order.
            return is_array($images)
                && count($images) === 3
                && str_contains((string) $images[0]['src'], 'primary.webp')
                && str_contains((string) $images[1]['src'], 'extra1.webp')
                && str_contains((string) $images[2]['src'], 'extra2.webp');
        });
    }

    public function test_the_product_category_maps_to_a_woocommerce_term(): void
    {
        $this->install();
        $this->configure();
        $category = PosCategory::query()->create(['name' => 'Men']);
        $product = $this->product(['pos_category_id' => $category->id]);

        Http::fake([
            // The store already has a "Men" category (matched by name on search).
            '*/wp-json/wc/v3/products/categories*' => Http::response([['id' => 42, 'name' => 'Men']], 200),
            '*/wp-json/wc/v3/products' => Http::response(['id' => 888], 201),
        ]);

        (new SyncProductToWooCommerce((int) $product->id))->handle(app(WooCommerceService::class));

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with(strtok($request->url(), '?'), '/products')) {
                return false;
            }
            $categories = $request['categories'] ?? [];

            return is_array($categories)
                && count($categories) === 1
                && (int) $categories[0]['id'] === 42;
        });
    }

    public function test_a_missing_category_is_created_on_the_store(): void
    {
        $this->install();
        $this->configure();
        $category = PosCategory::query()->create(['name' => 'Women']);
        $product = $this->product(['pos_category_id' => $category->id]);

        Http::fake([
            // Search returns nothing → the service creates the term, gets id 9.
            '*/wp-json/wc/v3/products/categories*' => Http::sequence()
                ->push([], 200)          // GET search: empty
                ->push(['id' => 9, 'name' => 'Women'], 201), // POST create
            '*/wp-json/wc/v3/products' => Http::response(['id' => 889], 201),
        ]);

        (new SyncProductToWooCommerce((int) $product->id))->handle(app(WooCommerceService::class));

        // The store was asked to create the missing category…
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_ends_with(strtok($request->url(), '?'), '/products/categories')
            && $request['name'] === 'Women');

        // …and the product carries that new term id.
        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with(strtok($request->url(), '?'), '/products')) {
                return false;
            }

            return (int) ($request['categories'][0]['id'] ?? 0) === 9;
        });
    }

    public function test_job_unpublish_sets_the_remote_listing_to_draft(): void
    {
        $this->install();
        $this->configure();
        $product = $this->product();

        WooCommerceProductLink::query()->create([
            'pos_product_id' => $product->id,
            'woo_id' => 900,
        ]);

        Http::fake(['*/wp-json/wc/v3/products/900' => Http::response(['id' => 900], 200)]);

        (new SyncProductToWooCommerce((int) $product->id, 'unpublish'))->handle(app(WooCommerceService::class));

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/products/900')
            && $request['status'] === 'draft');

        $this->assertSame('unpublished', WooCommerceProductLink::query()
            ->where('pos_product_id', $product->id)->value('last_status'));
    }

    public function test_a_failed_push_marks_the_link_and_throws(): void
    {
        $this->install();
        $this->configure();
        $product = $this->product();

        Http::fake(['*' => Http::response('bad request', 400)]);

        try {
            (new SyncProductToWooCommerce((int) $product->id))->handle(app(WooCommerceService::class));
            $this->fail('Expected a WooCommerceException.');
        } catch (\Modules\WooCommerce\Exceptions\WooCommerceException) {
            // expected
        }

        $this->assertSame('failed', WooCommerceProductLink::query()
            ->where('pos_product_id', $product->id)->value('last_status'));
    }

    public function test_sync_all_now_runs_synchronously_and_reports_outcomes(): void
    {
        $this->install();
        $this->configure();

        // Configured but the database has no products → clear, accurate message.
        $empty = Livewire::test(WooCommerceSettings::class)->call('syncAllNow');
        $this->assertStringContainsString('No active products', (string) $empty->get('syncMessage'));
        $this->assertFalse($empty->get('syncError'));

        // With a product + a healthy store → synced immediately (no queue).
        $product = $this->product();
        Http::fake(['*/wp-json/wc/v3/products' => Http::response(['id' => 321], 201)]);

        $ok = Livewire::test(WooCommerceSettings::class)->call('syncAllNow');
        $this->assertStringContainsString('synced', (string) $ok->get('syncMessage'));
        $this->assertFalse($ok->get('syncError'));
        $this->assertSame(321, WooCommerceProductLink::query()->where('pos_product_id', $product->id)->value('woo_id'));
    }

    public function test_sync_all_now_surfaces_a_store_error(): void
    {
        $this->install();
        $this->configure();
        $this->product();

        Http::fake(['*' => Http::response('Unauthorized', 401)]);

        $component = Livewire::test(WooCommerceSettings::class)->call('syncAllNow');

        $this->assertTrue($component->get('syncError'));
        $this->assertStringContainsString('failed', (string) $component->get('syncMessage'));
        $this->assertStringContainsString('401', (string) $component->get('syncMessage'));
    }

    public function test_saving_an_active_product_queues_a_push_when_enabled(): void
    {
        $this->install();
        $this->configure();
        // The module's boot() (and its model hooks) doesn't run after an in-test
        // install — register it explicitly, like PurchaseConfirmTest does.
        $this->app->register(WooCommerceServiceProvider::class);
        Bus::fake();

        $this->product();

        Bus::assertDispatched(SyncProductToWooCommerce::class);
    }

    public function test_a_retried_create_adopts_the_existing_listing_instead_of_duplicating(): void
    {
        // If the store is slow and the reply is lost, the product WAS created —
        // the ERP just never learned its id. Retrying used to create it again.
        $this->install();
        $this->configure();
        $product = $this->product();

        Http::fake([
            // The SKU lookup finds the listing the lost reply belonged to.
            '*/wp-json/wc/v3/products?sku=OUD-001' => Http::response([['id' => 888, 'sku' => 'OUD-001']], 200),
            '*/wp-json/wc/v3/products/888' => Http::response(['id' => 888], 200),
            '*/wp-json/wc/v3/products' => Http::response(['id' => 999], 201),
        ]);

        (new SyncProductToWooCommerce((int) $product->id))->handle(app(WooCommerceService::class));

        $link = WooCommerceProductLink::query()->where('pos_product_id', $product->id)->firstOrFail();
        $this->assertSame(888, $link->woo_id, 'the existing listing should be adopted');

        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/wp-json/wc/v3/products'));
    }
}
