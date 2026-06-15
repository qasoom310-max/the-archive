<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Enums\ModuleState;
use App\Erp\Modules\ModuleManager;
use App\Models\Ir\IrModule;
use App\Models\User;
use Database\Seeders\InventorySeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MoveState;
use Modules\Inventory\Livewire\InventoryOverview;
use Modules\Inventory\Livewire\StockTransferForm;
use Modules\Inventory\Livewire\StockTransfers;
use Modules\Inventory\Models\StockLocation;
use Modules\Inventory\Models\StockMove;
use Modules\Inventory\Models\StockOperationType;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;
use Modules\Pos\Models\PosProduct;
use Tests\TestCase;

final class InventoryModuleTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function installInventory(): void
    {
        app(ModuleManager::class)->install('inventory');
    }

    public function test_products_in_stock_kpi_counts_pos_products_with_stock(): void
    {
        $this->installInventory();
        app(ModuleManager::class)->install('pos');

        PosProduct::query()->create(['name' => 'In stock A', 'price' => 1, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 5]);
        PosProduct::query()->create(['name' => 'Empty', 'price' => 1, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 0]);
        PosProduct::query()->create(['name' => 'In stock B', 'price' => 1, 'tax_rate' => 0, 'active' => true, 'stock_on_hand' => 2]);

        Livewire::test(InventoryOverview::class)
            ->assertViewHas('kpis', fn (array $kpis): bool => $kpis['productsInStock'] === 2);
    }

    public function test_install_creates_double_entry_schema(): void
    {
        $this->installInventory();

        $this->assertSame(
            ModuleState::Installed,
            IrModule::query()->where('name', 'inventory')->sole()->state,
        );

        foreach (['warehouses', 'stock_locations', 'stock_operation_types', 'stock_moves', 'stock_quants'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "missing table {$table}");
        }
    }

    public function test_seeder_builds_topology_and_dashboard_counts_are_live(): void
    {
        $this->installInventory();
        $this->seed(InventorySeeder::class);

        $this->assertSame(1, Warehouse::query()->count());
        $this->assertSame('WH/Stock/Aisle A/Shelf 1', StockLocation::query()
            ->where('name', 'Shelf 1')->sole()->complete_name);
        $this->assertSame(LocationType::Vendor, StockLocation::query()
            ->where('name', 'Vendors')->sole()->type);

        $receipts = StockOperationType::query()->where('sequence_code', 'IN')->sole();
        $delivery = StockOperationType::query()->where('sequence_code', 'OUT')->sole();
        $internal = StockOperationType::query()->where('sequence_code', 'INT')->sole();

        $this->assertSame(3, $receipts->toProcessCount());
        $this->assertSame(1, $receipts->lateCount());
        $this->assertSame(2, $delivery->toProcessCount());
        $this->assertSame(1, $delivery->lateCount());
        $this->assertSame(1, $internal->toProcessCount());
        $this->assertSame(0, $internal->lateCount());
    }

    public function test_double_entry_valuation_rule(): void
    {
        $this->installInventory();

        $internal = StockLocation::query()->create(['name' => 'Stock', 'type' => LocationType::Internal]);
        $internal2 = StockLocation::query()->create(['name' => 'Stock 2', 'type' => LocationType::Internal]);
        $customer = StockLocation::query()->create(['name' => 'Customers', 'type' => LocationType::Customer]);
        $vendor = StockLocation::query()->create(['name' => 'Vendors', 'type' => LocationType::Vendor]);
        $production = StockLocation::query()->create(['name' => 'Production', 'type' => LocationType::Production]);

        $mk = static function (StockLocation $from, StockLocation $to): StockMove {
            return StockMove::query()->create([
                'reference' => 'T', 'product_qty' => 1, 'state' => MoveState::Draft,
                'source_location_id' => $from->id, 'dest_location_id' => $to->id,
            ]);
        };

        $this->assertTrue($mk($internal, $customer)->affectsValuation());   // delivery
        $this->assertTrue($mk($vendor, $internal)->affectsValuation());     // receipt
        $this->assertFalse($mk($internal, $internal2)->affectsValuation()); // internal transfer
        $this->assertFalse($mk($production, $internal)->affectsValuation()); // not cust/vendor
        $this->assertFalse($mk($customer, $vendor)->affectsValuation());    // both external
    }

    public function test_overview_dashboard_renders_operation_cards(): void
    {
        $this->installInventory();
        $this->seed(InventorySeeder::class);

        Livewire::test(InventoryOverview::class)
            ->assertOk()
            ->assertSee('Inventory Overview')
            ->assertSee('Receipts')
            ->assertSee('Delivery Orders')
            ->assertSee('Internal Transfers')
            ->assertSee('PoS Orders');
    }

    public function test_processing_a_move_atomically_shifts_quants_and_is_idempotent(): void
    {
        $this->installInventory();

        $src = StockLocation::query()->create(['name' => 'Stock', 'type' => LocationType::Internal]);
        $dest = StockLocation::query()->create(['name' => 'Customers', 'type' => LocationType::Customer]);

        StockQuant::query()->create([
            'stock_location_id' => $src->id, 'product_id' => 7, 'quantity' => 100,
        ]);

        $move = StockMove::query()->create([
            'reference' => 'WH/OUT/00001', 'product_id' => 7, 'product_qty' => 10,
            'source_location_id' => $src->id, 'dest_location_id' => $dest->id,
            'state' => MoveState::Assigned,
        ]);

        $move->process();

        $this->assertSame(MoveState::Done, $move->refresh()->state);
        $this->assertNotNull($move->done_at);
        $this->assertEqualsWithDelta(90.0, StockQuant::query()
            ->where('stock_location_id', $src->id)->where('product_id', 7)->sole()->quantity, 0.001);
        $this->assertEqualsWithDelta(10.0, StockQuant::query()
            ->where('stock_location_id', $dest->id)->where('product_id', 7)->sole()->quantity, 0.001);

        // Re-validating must not double-apply.
        $move->process();
        $this->assertEqualsWithDelta(90.0, StockQuant::query()
            ->where('stock_location_id', $src->id)->where('product_id', 7)->sole()->quantity, 0.001);
        $this->assertSame(1, StockQuant::query()->where('product_id', 7)->where('stock_location_id', $dest->id)->count());
    }

    public function test_create_transfer_then_validate_from_list_updates_stock(): void
    {
        $this->installInventory();
        $this->seed(InventorySeeder::class);

        $receipts = StockOperationType::query()->where('sequence_code', 'IN')->sole();

        Livewire::test(StockTransferForm::class, ['type' => $receipts->id])
            ->assertSet('sourceId', $receipts->default_source_location_id)
            ->assertSet('destId', $receipts->default_dest_location_id)
            ->set('productId', 99)
            ->set('qty', '5')
            ->call('save')
            ->assertRedirect('/app/inventory/transfers?type=' . $receipts->id);

        $move = StockMove::query()->where('product_id', 99)->sole();
        $this->assertSame(MoveState::Assigned, $move->state);

        Livewire::test(StockTransfers::class, ['type' => $receipts->id])
            ->call('validateMove', $move->id);

        $this->assertSame(MoveState::Done, $move->refresh()->state);
        $this->assertEqualsWithDelta(5.0, StockQuant::query()
            ->where('stock_location_id', $receipts->default_dest_location_id)
            ->where('product_id', 99)->sole()->quantity, 0.001);
    }
}
