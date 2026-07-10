<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plain-language stock entry: `stock_on_hand` = how many units in hand,
 * `pack_size` = the size of one unit (e.g. 20 for a 20-litre drum, or 1 if you
 * count in the unit itself), `unit` = that size's unit (Liter / mL / …). The
 * millilitres in one unit (`ml_per_unit`) is derived on save from pack_size ×
 * the unit, so nobody types a raw ml figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_ingredients', function (Blueprint $table): void {
            $table->decimal('pack_size', 14, 3)->default(1)->after('unit');
        });
    }

    public function down(): void
    {
        Schema::table('pos_ingredients', function (Blueprint $table): void {
            $table->dropColumn('pack_size');
        });
    }
};
