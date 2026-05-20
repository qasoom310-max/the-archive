<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MoveState;
use Modules\Inventory\Models\StockLocation;
use Modules\Inventory\Models\StockMove;
use Modules\Inventory\Models\StockOperationType;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;

/**
 * Default warehouse topology + operation types + a little demo traffic
 * so the Inventory Overview shows real numbers. No-ops until the
 * Inventory module is installed, and once seeded (idempotent guard).
 */
final class InventorySeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('stock_operation_types') || Warehouse::query()->exists()) {
            return;
        }

        $warehouse = Warehouse::query()->create([
            'name' => 'Main Warehouse',
            'code' => 'WH',
        ]);

        $loc = fn (string $name, LocationType $type, ?int $parent = null, ?string $path = null): StockLocation
            => StockLocation::query()->create([
                'name' => $name,
                'complete_name' => $path ?? $name,
                'parent_id' => $parent,
                'warehouse_id' => $type === LocationType::Internal || $type === LocationType::View
                    ? $warehouse->id : null,
                'type' => $type,
            ]);

        $vendors = $loc('Vendors', LocationType::Vendor);
        $customers = $loc('Customers', LocationType::Customer);
        $loc('Inventory Adjustment', LocationType::Inventory);
        $loc('Production', LocationType::Production);
        $loc('Inter-Warehouse Transit', LocationType::Transit);

        $whView = $loc('WH', LocationType::View, null, 'WH');
        $warehouse->update(['view_location_id' => $whView->id]);

        $stock = $loc('Stock', LocationType::Internal, $whView->id, 'WH/Stock');
        $aisle = $loc('Aisle A', LocationType::Internal, $stock->id, 'WH/Stock/Aisle A');
        $loc('Shelf 1', LocationType::Internal, $aisle->id, 'WH/Stock/Aisle A/Shelf 1');

        $types = [
            ['Receipts', 'incoming', 'IN', $vendors->id, $stock->id, 10],
            ['Delivery Orders', 'outgoing', 'OUT', $stock->id, $customers->id, 20],
            ['Internal Transfers', 'internal', 'INT', $stock->id, $stock->id, 30],
            ['PoS Orders', 'pos', 'POS', $stock->id, $customers->id, 40],
        ];

        $opType = [];
        foreach ($types as [$name, $code, $seqCode, $src, $dest, $seq]) {
            $opType[$seqCode] = StockOperationType::query()->create([
                'name' => $name,
                'code' => $code,
                'sequence_code' => $seqCode,
                'warehouse_id' => $warehouse->id,
                'default_source_location_id' => $src,
                'default_dest_location_id' => $dest,
                'sequence' => $seq,
            ]);
        }

        $move = function (string $seqCode, int $n, MoveState $state, ?Carbon $scheduled) use ($opType): void {
            $t = $opType[$seqCode];
            StockMove::query()->create([
                'reference' => sprintf('WH/%s/%05d', $seqCode, $n),
                'stock_operation_type_id' => $t->id,
                'product_id' => null,
                'product_qty' => 1 + $n,
                'source_location_id' => $t->default_source_location_id,
                'dest_location_id' => $t->default_dest_location_id,
                'state' => $state,
                'scheduled_at' => $scheduled,
                'done_at' => $state === MoveState::Done ? Carbon::now() : null,
            ]);
        };

        // Receipts: 2 to process, 1 late, 1 done.
        $move('IN', 1, MoveState::Assigned, Carbon::now()->addDay());
        $move('IN', 2, MoveState::Confirmed, Carbon::now()->addDays(2));
        $move('IN', 3, MoveState::Draft, Carbon::now()->subDay());      // late
        $move('IN', 4, MoveState::Done, Carbon::now()->subDays(3));

        // Delivery: 1 to process, 1 late.
        $move('OUT', 1, MoveState::Assigned, Carbon::now()->addDay());
        $move('OUT', 2, MoveState::Confirmed, Carbon::now()->subHours(5)); // late

        // Internal: 1 to process.
        $move('INT', 1, MoveState::Draft, null);

        // A little on-hand stock so the KPI is non-zero.
        StockQuant::query()->create([
            'stock_location_id' => $stock->id,
            'product_id' => 1,
            'quantity' => 120,
        ]);
        StockQuant::query()->create([
            'stock_location_id' => $aisle->id,
            'product_id' => 2,
            'quantity' => 45,
        ]);
    }
}
