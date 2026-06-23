<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Livewire\PosStockReport;
use Modules\Pos\Models\PosCondiment;
use Modules\Pos\Models\PosProduct;
use Tests\TestCase;

/**
 * The Odoo-style stock report: every product bucketed into in / low / out
 * of stock, reusing the daily report's LOW_STOCK_THRESHOLD (10).
 */
final class PosStockReportTest extends TestCase
{
    use DatabaseMigrations;

    private function seedProducts(): void
    {
        PosProduct::query()->create(['name' => 'Plenty', 'price' => 1, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 50]);
        PosProduct::query()->create(['name' => 'Running Low', 'price' => 1, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 5]);
        PosProduct::query()->create(['name' => 'Sold Out', 'price' => 1, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 0]);
    }

    public function test_summary_buckets_products_by_stock_health(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
        $this->seedProducts();

        Livewire::test(PosStockReport::class)
            ->assertViewHas('summary', fn (array $s): bool => $s['total'] === 3
                && $s['in'] === 2   // 50 + 5 (stock > 0)
                && $s['low'] === 1  // 5 (0 < stock <= 10)
                && $s['out'] === 1) // 0
            ->assertSee('Plenty')
            ->assertSee('Sold Out');
    }

    public function test_out_of_stock_filter_shows_only_empty_products(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');
        $this->seedProducts();

        Livewire::test(PosStockReport::class)
            ->call('setFilter', 'out')
            ->assertSee('Sold Out')
            ->assertDontSee('Plenty');
    }

    public function test_requires_pos_product_read(): void
    {
        // A plain user with no pos.product ACL is denied (deny-by-default).
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');

        // Re-act as a plain user (no ACL) now that POS is installed.
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(PosStockReport::class)->assertForbidden();
    }

    public function test_valuation_sums_on_hand_times_cost(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');

        PosProduct::query()->create(['name' => 'A', 'price' => 5, 'cost_price' => 2, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 50]);
        PosProduct::query()->create(['name' => 'B', 'price' => 5, 'cost_price' => 1, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 5]);
        PosProduct::query()->create(['name' => 'C', 'price' => 5, 'cost_price' => 3, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 0]);

        // 50*2 + 5*1 + 0*3 = 105
        Livewire::test(PosStockReport::class)
            ->assertViewHas('summary', fn (array $s): bool => abs($s['value'] - 105.0) < 0.001);
    }

    public function test_per_product_reorder_point_flags_low(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');

        // Stock 15 > global threshold (10) would normally be "in", but the
        // product's own reorder point of 20 makes it "low".
        PosProduct::query()->create(['name' => 'Custom', 'price' => 1, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 15, 'reorder_point' => 20]);
        PosProduct::query()->create(['name' => 'Normal', 'price' => 1, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 15]);

        Livewire::test(PosStockReport::class)
            ->assertViewHas('summary', fn (array $s): bool => $s['low'] === 1 && $s['in'] === 2);
    }

    public function test_inactive_products_excluded_by_default_and_shown_on_toggle(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');

        PosProduct::query()->create(['name' => 'Live', 'price' => 1, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 5]);
        PosProduct::query()->create(['name' => 'Discontinued', 'price' => 1, 'tax_rate' => 0, 'active' => false, 'stock_on_hand' => 5]);

        $component = Livewire::test(PosStockReport::class)
            ->assertViewHas('summary', fn (array $s): bool => $s['total'] === 1)
            ->assertSee('Live')
            ->assertDontSee('Discontinued');

        $component->set('includeInactive', true)
            ->assertViewHas('summary', fn (array $s): bool => $s['total'] === 2)
            ->assertSee('Discontinued');
    }

    public function test_adjust_sets_stock_on_hand(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');

        $product = PosProduct::query()->create(['name' => 'Restock me', 'price' => 1, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 0]);

        Livewire::test(PosStockReport::class)
            ->call('openAdjust', $product->id)
            ->set('adjustQty', '42')
            ->call('saveAdjust');

        $this->assertEqualsWithDelta(42.0, $product->fresh()?->stock_on_hand, 0.001);
    }

    public function test_condiments_appear_and_count_in_the_summary(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');

        PosProduct::query()->create(['name' => 'Burger', 'price' => 1, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 50]);
        PosCondiment::query()->create(['name' => 'Extra cheese', 'price' => 0.5, 'active' => true, 'stock_on_hand' => 3]);  // low
        PosCondiment::query()->create(['name' => 'No ice', 'price' => 0, 'active' => true, 'stock_on_hand' => 0]);          // out

        Livewire::test(PosStockReport::class)
            ->assertViewHas('summary', fn (array $s): bool => $s['total'] === 3 // 1 product + 2 condiments
                && $s['in'] === 2   // Burger (50) + Extra cheese (3) both have stock; low ⊂ in
                && $s['low'] === 1  // Extra cheese (≤ 10)
                && $s['out'] === 1) // No ice (0)
            ->assertSee('Extra cheese')
            ->assertSee('No ice')
            ->assertSee('Add-on');
    }

    public function test_out_filter_includes_out_of_stock_condiments(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');

        PosProduct::query()->create(['name' => 'In Stock Product', 'price' => 1, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 50]);
        PosCondiment::query()->create(['name' => 'Empty Add-on', 'price' => 0, 'active' => true, 'stock_on_hand' => 0]);

        Livewire::test(PosStockReport::class)
            ->call('setFilter', 'out')
            ->assertSee('Empty Add-on')
            ->assertDontSee('In Stock Product');
    }

    public function test_adjust_sets_a_condiment_stock(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('pos');

        $condiment = PosCondiment::query()->create(['name' => 'Sauce', 'price' => 0, 'active' => true, 'stock_on_hand' => 0]);

        Livewire::test(PosStockReport::class)
            ->call('openAdjust', $condiment->id, 'condiment')
            ->set('adjustQty', '25')
            ->call('saveAdjust');

        $this->assertEqualsWithDelta(25.0, $condiment->fresh()?->stock_on_hand, 0.001);
    }
}
