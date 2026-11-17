<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A car's sales target is now ONE figure per year, typed by the manager.
 *
 * The years after it are worked out, not typed: a car that reached its target
 * carries last year's figure plus 10% into the new year, and one that missed
 * keeps the same figure. The monthly targets are worked out too, by sharing
 * the year out the way last year's sales fell. So only the typed years are
 * stored here; everything else is derived from them and from what was sold.
 *
 * The two old columns on the car (monthly_target, yearly_target) are left in
 * place and untouched. Their figures are copied in as this year's target so
 * nothing the office already set is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_vehicle_targets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedSmallInteger('year');
            $table->decimal('amount', 12, 3)->default(0);
            $table->timestamps();

            $table->unique(['vehicle_id', 'year'], 'rental_vehicle_target_unique');
        });

        if (! Schema::hasColumn('rental_vehicles', 'yearly_target')) {
            return;
        }

        $year = (int) date('Y');
        $now = date('Y-m-d H:i:s');

        foreach (DB::table('rental_vehicles')->get(['id', 'monthly_target', 'yearly_target']) as $car) {
            $yearly = (float) $car->yearly_target;
            // A car that only ever had a monthly figure keeps twelve of them.
            $amount = $yearly > 0.0 ? $yearly : round((float) $car->monthly_target * 12, 3);

            if ($amount <= 0.0) {
                continue;
            }

            DB::table('rental_vehicle_targets')->insert([
                'vehicle_id' => $car->id,
                'year' => $year,
                'amount' => $amount,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_vehicle_targets');
    }
};
