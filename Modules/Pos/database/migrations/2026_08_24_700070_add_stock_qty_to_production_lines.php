<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record how much stock a production line ACTUALLY took, in stock units.
 *
 * A reversal used to recompute the quantity from the material's pack size
 * ("Each unit is ... ml") as it stands TODAY. Correct that figure between the
 * run and the reversal and the reversal hands back a different amount than was
 * taken — the difference disappearing for good. Storing what was deducted makes
 * the reversal exact; older lines with no figure fall back to the old maths.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_production_lines') || Schema::hasColumn('pos_production_lines', 'stock_qty')) {
            return;
        }

        Schema::table('pos_production_lines', function (Blueprint $table): void {
            $table->decimal('stock_qty', 12, 3)->nullable()->after('ml_used');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_production_lines') || ! Schema::hasColumn('pos_production_lines', 'stock_qty')) {
            return;
        }

        Schema::table('pos_production_lines', function (Blueprint $table): void {
            $table->dropColumn('stock_qty');
        });
    }
};
