<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Modules\Contacts\Models\Partner;
use Modules\Pos\Models\PosCondiment;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProductRecipe;
use Modules\Purchases\Enums\PurchaseState;
use Modules\Purchases\Http\Controllers\PurchaseReorderExportController;
use Modules\Purchases\Http\Controllers\PurchaseReorderPdfController;
use Modules\Purchases\Livewire\PurchaseReorder;
use Modules\Purchases\Models\Purchase;
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

        $names = app(PurchaseReorderData::class)->rows()->pluck('item.name')->all();

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
        $rows = app(PurchaseReorderData::class)->rows()->keyBy('item.name');

        $this->assertTrue($rows->has('Flour'));
        $this->assertSame('low', $rows->get('Flour')->item->status);
        $this->assertSame(80.0, $rows->get('Flour')->item->reorderPoint);

        $this->assertTrue($rows->has('Cheese'));
        $this->assertSame('low', $rows->get('Cheese')->item->status);
        $this->assertSame(80.0, $rows->get('Cheese')->item->reorderPoint);
    }

    public function test_preferred_supplier_is_shown_as_the_vendor(): void
    {
        $vendor = Partner::query()->create(['name' => 'Acme Foods', 'phone' => '111-2222', 'is_company' => true]);
        PosIngredient::query()->create(['name' => 'Flour', 'cost_price' => 0.8, 'stock_on_hand' => 0, 'supplier_id' => $vendor->id]);

        $row = app(PurchaseReorderData::class)->rows()->firstWhere('item.name', 'Flour');

        $this->assertNotNull($row);
        $this->assertSame('Acme Foods', $row->vendorName);
        $this->assertSame('111-2222', $row->vendorPhone);
    }

    public function test_falls_back_to_the_last_vendor_bought_from(): void
    {
        $vendor = Partner::query()->create(['name' => 'Best Eggs Co', 'phone' => '999-000', 'is_company' => true]);
        // No preferred supplier set on the ingredient…
        $eggs = PosIngredient::query()->create(['name' => 'Eggs', 'cost_price' => 0.1, 'stock_on_hand' => 0]);

        // …but a confirmed bill bought it from this vendor. (Built directly so
        // stock stays 0 — we want it to remain "out" but with purchase history.)
        $purchase = Purchase::query()->create([
            'date' => '2026-06-24',
            'is_stock_purchase' => true,
            'partner_id' => $vendor->id,
            'state' => PurchaseState::Confirmed,
        ]);
        $purchase->lines()->create([
            'pos_ingredient_id' => $eggs->id,
            'description' => 'Eggs',
            'quantity' => 30,
            'unit_cost' => 0.1,
        ]);

        $row = app(PurchaseReorderData::class)->rows()->firstWhere('item.name', 'Eggs');

        $this->assertNotNull($row);
        $this->assertSame('Best Eggs Co', $row->vendorName);
        $this->assertSame('999-000', $row->vendorPhone);
    }

    public function test_preferred_supplier_wins_over_last_bought(): void
    {
        $preferred = Partner::query()->create(['name' => 'Preferred Supplier', 'phone' => '1', 'is_company' => true]);
        $other = Partner::query()->create(['name' => 'Old Supplier', 'phone' => '2', 'is_company' => true]);
        $oil = PosIngredient::query()->create(['name' => 'Oil', 'cost_price' => 5, 'stock_on_hand' => 0, 'supplier_id' => $preferred->id]);

        $purchase = Purchase::query()->create([
            'date' => '2026-06-24',
            'is_stock_purchase' => true,
            'partner_id' => $other->id,
            'state' => PurchaseState::Confirmed,
        ]);
        $purchase->lines()->create(['pos_ingredient_id' => $oil->id, 'description' => 'Oil', 'quantity' => 1, 'unit_cost' => 5]);

        $row = app(PurchaseReorderData::class)->rows()->firstWhere('item.name', 'Oil');

        $this->assertSame('Preferred Supplier', $row?->vendorName);
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

    public function test_pdf_export_renders(): void
    {
        $this->seedCatalogue();

        $response = (new PurchaseReorderPdfController())(
            Request::create('/app/purchases/reorder/pdf', 'GET'),
            app(PurchaseReorderData::class),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }
}
