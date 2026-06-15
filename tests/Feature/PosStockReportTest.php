<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Pos\Livewire\PosStockReport;
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
}
