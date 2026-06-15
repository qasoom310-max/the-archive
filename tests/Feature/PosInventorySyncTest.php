<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MoveState;
use Modules\Inventory\Models\StockLocation;
use Modules\Inventory\Models\StockMove;
use Modules\Inventory\Models\StockQuant;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Enums\SessionState;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosPaymentMethod;
use Modules\Pos\Models\PosProduct;
use Modules\Pos\Models\PosProductRecipe;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Services\PosInventoryBridge;
use Tests\TestCase;

/**
 * Real-time POS → Inventory stock sync: a stock edit / sale mirrors POS
 * on-hand onto the double-entry Inventory ledger (quant at the main Stock
 * location + a Done adjustment move).
 */
final class PosInventorySyncTest extends TestCase
{
    use DatabaseMigrations;

    private int $stockLocationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('inventory');
        app(ModuleManager::class)->install('pos');

        $stock = StockLocation::query()->create(['name' => 'Stock', 'type' => LocationType::Internal->value, 'active' => true]);
        StockLocation::query()->create(['name' => 'Inventory adjustment', 'type' => LocationType::Inventory->value, 'active' => true]);
        $this->stockLocationId = (int) $stock->id;
    }

    private function quantFor(int $productId): float
    {
        $q = StockQuant::query()
            ->where('stock_location_id', $this->stockLocationId)
            ->where('product_id', $productId)
            ->first();

        return $q !== null ? (float) $q->quantity : 0.0;
    }

    public function test_bridge_mirrors_quantity_and_records_a_move(): void
    {
        $bridge = app(PosInventoryBridge::class);

        $bridge->sync(777, 30.0, 'test up');
        $this->assertEqualsWithDelta(30.0, $this->quantFor(777), 0.001);
        $this->assertSame(1, StockMove::query()->where('product_id', 777)->where('state', MoveState::Done->value)->count());

        // A second sync to a lower value posts another (downward) adjustment.
        $bridge->sync(777, 25.0, 'test down');
        $this->assertEqualsWithDelta(25.0, $this->quantFor(777), 0.001);
        $this->assertSame(2, StockMove::query()->where('product_id', 777)->count());
    }

    public function test_no_move_when_quantity_is_unchanged(): void
    {
        $bridge = app(PosInventoryBridge::class);

        $bridge->sync(888, 10.0, 'first');
        $bridge->sync(888, 10.0, 'same'); // delta 0 → no-op

        $this->assertSame(1, StockMove::query()->where('product_id', 888)->count());
    }

    public function test_saved_hook_syncs_a_stock_edit(): void
    {
        // Mirror the provider's saved hook (module boot() doesn't run after an
        // in-test install — the same gap PurchaseConfirmTest works around).
        PosProduct::saved(static function (PosProduct $p): void {
            if ($p->wasChanged('stock_on_hand')) {
                app(PosInventoryBridge::class)->sync((int) $p->id, (float) $p->stock_on_hand, 'edit');
            }
        });

        $product = PosProduct::query()->create(['name' => 'Edited', 'price' => 1, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 0]);
        $product->stock_on_hand = 12.0;
        $product->save();

        $this->assertEqualsWithDelta(12.0, $this->quantFor((int) $product->id), 0.001);
    }

    public function test_sale_consumption_syncs_component_stock(): void
    {
        $component = PosProduct::query()->create(['name' => 'Beans', 'price' => 0, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 100]);
        $coffee = PosProduct::query()->create(['name' => 'Coffee', 'price' => 2, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 0]);
        PosProductRecipe::query()->create(['parent_product_id' => $coffee->id, 'component_product_id' => $component->id, 'quantity_consumed' => 1.0]);
        $cash = PosPaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true, 'sequence' => 1, 'active' => true]);

        $session = PosSession::query()->create(['reference' => 'POS-S/0001', 'state' => SessionState::Opened, 'opening_cash' => 0, 'opened_at' => now()]);
        $order = PosOrder::query()->create(['pos_session_id' => $session->id, 'reference' => 'POS/' . $session->id . '/0001', 'state' => OrderState::Draft]);
        $line = $order->lines()->make(['pos_product_id' => $coffee->id, 'name' => 'Coffee', 'qty' => 2, 'unit_price' => 2, 'discount' => 0, 'tax_rate' => 0]);
        $line->recompute();
        $line->save();
        $order->recalculate();
        $order->registerPayment($cash, 4.0);
        $order->finalizeSale();

        // 2 coffees consumed 2 beans → 98 on hand, mirrored to the ledger.
        $this->assertEqualsWithDelta(98.0, (float) $component->fresh()?->stock_on_hand, 0.001);
        $this->assertEqualsWithDelta(98.0, $this->quantFor((int) $component->id), 0.001);
    }
}
