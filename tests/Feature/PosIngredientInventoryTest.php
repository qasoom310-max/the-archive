<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Database\Seeders\InventorySeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Models\StockLocation;
use Modules\Inventory\Models\StockMove;
use Modules\Inventory\Models\StockQuant;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Models\PosIngredient;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosPaymentMethod;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProductRecipe;
use Modules\Pos\Models\PosSession;
use Modules\Purchases\Models\Purchase;
use Modules\Purchases\Services\PurchaseConfirmer;
use Tests\TestCase;

/**
 * Ingredient ↔ Inventory visibility: raw-material purchases and recipe
 * consumption post Done audit moves (item_type = 'ingredient') so the
 * Inventory dashboard reflects them — WITHOUT ever touching the product-keyed
 * `stock_quants` ledger (ingredient on-hand stays authoritative in
 * `pos_ingredients`).
 */
final class PosIngredientInventoryTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        // Installing purchases pulls contacts + pos + inventory.
        app(ModuleManager::class)->install('purchases');
        // Warehouse topology (Vendors / Stock / Inventory Adjustment locations).
        (new InventorySeeder())->run();
    }

    private function stockLocationId(): int
    {
        return (int) StockLocation::query()
            ->where('type', LocationType::Internal->value)
            ->where('name', 'Stock')->value('id');
    }

    public function test_confirming_an_ingredient_purchase_posts_a_done_receipt_move(): void
    {
        $flour = PosIngredient::query()->create(['name' => 'Flour', 'cost_price' => 0.8, 'stock_on_hand' => 10]);

        $purchase = Purchase::query()->create(['date' => '2026-06-24', 'is_stock_purchase' => true]);
        $purchase->lines()->create([
            'pos_ingredient_id' => $flour->id,
            'description' => 'Flour',
            'quantity' => 20,
            'unit_cost' => 0.8,
        ]);

        // Snapshot the (product-keyed) quant ledger so we can prove the
        // ingredient receipt never mutates it.
        $quantRowsBefore = StockQuant::query()->count();
        $quantSumBefore = (float) StockQuant::query()->sum('quantity');

        app(PurchaseConfirmer::class)->confirm($purchase);

        // On-hand raised on the POS side.
        $this->assertSame(30.0, (float) $flour->fresh()?->stock_on_hand);

        // A Done ingredient receipt move (Vendor → Stock) is recorded.
        $move = StockMove::query()
            ->where('item_type', 'ingredient')
            ->where('product_id', $flour->id)
            ->first();

        $this->assertNotNull($move);
        $this->assertSame('done', $move->state->value);
        $this->assertSame(20.0, (float) $move->product_qty);
        $this->assertSame($this->stockLocationId(), (int) $move->dest_location_id);

        // It must NOT touch the product-keyed quant ledger (no process()).
        $this->assertSame($quantRowsBefore, StockQuant::query()->count(), 'Ingredient receipt must not add a stock_quant row.');
        $this->assertSame($quantSumBefore, (float) StockQuant::query()->sum('quantity'), 'Ingredient receipt must not change any quant quantity.');
    }

    public function test_selling_a_product_posts_an_ingredient_consumption_move(): void
    {
        $burger = PosProduct::query()->create(['name' => 'Burger', 'price' => 5, 'tax_rate' => 0, 'active' => true]);
        $flour = PosIngredient::query()->create(['name' => 'Flour', 'cost_price' => 0.8, 'stock_on_hand' => 50]);
        PosProductRecipe::query()->create([
            'parent_product_id' => $burger->id,
            'component_ingredient_id' => $flour->id,
            'quantity_consumed' => 2,
        ]);
        $cash = PosPaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true, 'sequence' => 1, 'active' => true]);

        // Snapshot the quant ledger — consuming an ingredient must not mutate it.
        $quantRowsBefore = StockQuant::query()->count();
        $quantSumBefore = (float) StockQuant::query()->sum('quantity');

        $session = PosSession::query()->create(['reference' => 'POS-S/0001', 'state' => SessionState::Opened, 'opening_cash' => 0, 'opened_at' => now()]);
        $order = PosOrder::query()->create(['pos_session_id' => $session->id, 'reference' => 'POS/1/0007', 'state' => OrderState::Draft]);
        $line = $order->lines()->make(['pos_product_id' => $burger->id, 'name' => 'Burger', 'qty' => 3, 'unit_price' => 5, 'discount' => 0, 'tax_rate' => 0]);
        $line->recompute();
        $line->save();
        $order->recalculate();
        $order->registerPayment($cash, (float) $order->total);
        $order->finalizeSale();

        // 3 burgers × 2 flour = 6 consumed → 44 left.
        $this->assertSame(44.0, (float) $flour->fresh()?->stock_on_hand);

        // A Done ingredient consumption move (Stock → Inventory) is recorded.
        $move = StockMove::query()
            ->where('item_type', 'ingredient')
            ->where('product_id', $flour->id)
            ->where('reference', 'POS sale POS/1/0007')
            ->first();

        $this->assertNotNull($move);
        $this->assertSame('done', $move->state->value);
        $this->assertSame(6.0, (float) $move->product_qty);
        $this->assertSame($this->stockLocationId(), (int) $move->source_location_id);

        // No product-keyed quant touched by the ingredient consumption.
        $this->assertSame($quantRowsBefore, StockQuant::query()->count());
        $this->assertSame($quantSumBefore, (float) StockQuant::query()->sum('quantity'));
    }
}
