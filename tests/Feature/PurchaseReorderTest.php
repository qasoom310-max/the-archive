<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Modules\Pos\Models\PosCondiment;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProductRecipe;
use Modules\Purchases\Http\Controllers\PurchaseReorderExportController;
use Modules\Purchases\Livewire\PurchaseReorder;
use Modules\Purchases\Services\PurchaseReorderData;
use Tests\TestCase;

/**
 * The Reorder Report: the buying team's shopping list of purchasable items at
 * or below their minimum. Resale products + ingredients + condiments that are
 * low/out appear; well-stocked items and crafted (recipe-backed) products do
 * not.
 */
final class PurchaseReorderTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('purchases');
    }

    /**
     * Build a representative catalogue. Stock 0 = out (always below the
     * threshold); stock 9999 = comfortably in stock (threshold-independent).
     *
     * @return array<string, mixed>
     */
    private function seedCatalogue(): array
    {
        $flour = PosIngredient::query()->create(['name' => 'Flour', 'cost_price' => 0.8, 'stock_on_hand' => 0]);     // out → appears
        $sugar = PosIngredient::query()->create(['name' => 'Sugar', 'cost_price' => 0.9, 'stock_on_hand' => 9999]);  // in  → hidden
        $ketchup = PosCondiment::query()->create(['name' => 'Ketchup', 'price' => 0.2, 'stock_on_hand' => 0, 'active' => true]); // out → appears
        $pepsi = PosProduct::query()->create(['name' => 'Pepsi', 'price' => 1, 'tax_rate' => 0, 'stock_on_hand' => 0, 'active' => true]); // resale, out → appears
        $sandwich = PosProduct::query()->create(['name' => 'Egg Sandwich', 'price' => 3, 'tax_rate' => 0, 'stock_on_hand' => 0, 'active' => true]); // crafted, out → hidden
        PosProductRecipe::query()->create([
            'parent_product_id' => $sandwich->id,
            'component_ingredient_id' => $flour->id,
            'quantity_consumed' => 1,
        ]);

        return compact('flour', 'sugar', 'ketchup', 'pepsi', 'sandwich');
    }

    public function test_lists_low_and_out_purchasable_items_only(): void
    {
        $this->seedCatalogue();

        $names = app(PurchaseReorderData::class)->rows()->pluck('name')->all();

        $this->assertContains('Flour', $names);     // ingredient, out
        $this->assertContains('Ketchup', $names);   // condiment, out
        $this->assertContains('Pepsi', $names);     // resale product, out
        $this->assertNotContains('Sugar', $names);  // well stocked
        $this->assertNotContains('Egg Sandwich', $names); // crafted — not purchased
    }

    public function test_summary_counts_the_reorder_rows(): void
    {
        $this->seedCatalogue();

        $summary = app(PurchaseReorderData::class)->summary();

        // Flour + Ketchup + Pepsi are all out of stock.
        $this->assertSame(3, $summary['total']);
        $this->assertSame(3, $summary['out']);
        $this->assertSame(0, $summary['low']);
    }

    public function test_screen_renders_and_search_filters(): void
    {
        $this->seedCatalogue();

        Livewire::test(PurchaseReorder::class)
            ->assertOk()
            ->assertSee('Flour')
            ->assertSee('Pepsi')
            ->assertDontSee('Sugar')
            ->assertDontSee('Egg Sandwich')
            ->set('search', 'Flour')
            ->assertSee('Flour')
            ->assertDontSee('Pepsi');
    }

    public function test_per_item_reorder_point_flags_otherwise_in_stock_items(): void
    {
        // 50 on hand is well above the global threshold (10), so by the global
        // rule both would read "in stock"…
        $flour = PosIngredient::query()->create(['name' => 'Flour', 'cost_price' => 0.8, 'stock_on_hand' => 50, 'reorder_point' => 80]);
        $cheese = PosCondiment::query()->create(['name' => 'Cheese', 'price' => 0.3, 'stock_on_hand' => 50, 'reorder_point' => 80, 'active' => true]);

        // …but each sets its own minimum of 80, so both are flagged Low.
        $rows = app(PurchaseReorderData::class)->rows()->keyBy('name');

        $this->assertTrue($rows->has('Flour'));
        $this->assertSame('low', $rows->get('Flour')->status);
        $this->assertSame(80.0, $rows->get('Flour')->reorderPoint);

        $this->assertTrue($rows->has('Cheese'));
        $this->assertSame('low', $rows->get('Cheese')->status);
        $this->assertSame(80.0, $rows->get('Cheese')->reorderPoint);
    }

    public function test_csv_export_streams_the_rows(): void
    {
        $this->seedCatalogue();

        // Invoke the controller directly — module web routes aren't mounted in
        // the test harness (the known module-boot gap), so a $this->get() would
        // 404. The Livewire/data tests above already cover the row logic.
        $response = (new PurchaseReorderExportController())(
            Request::create('/app/purchases/reorder/export', 'GET'),
            app(PurchaseReorderData::class),
        );

        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();

        $this->assertStringContainsString('Flour', $csv);
        $this->assertStringContainsString('Ketchup', $csv);
        $this->assertStringNotContainsString('Sugar', $csv);
        $this->assertStringNotContainsString('Egg Sandwich', $csv);
    }
}
