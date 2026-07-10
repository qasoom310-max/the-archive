<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Container size for materials bought in fixed volumes (perfumes production):
 * `ml_per_unit` is the millilitres in one purchased unit — e.g. a 20 L drum of
 * ethanol = 20000. Stock stays a count of units; production consumes ML and
 * deducts `ml_used ÷ ml_per_unit` of a unit. Null = the item is already tracked
 * in ML (stock is ML directly).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_ingredients', function (Blueprint $table): void {
            $table->decimal('ml_per_unit', 14, 3)->nullable()->after('unit');
        });
    }

    public function down(): void
    {
        Schema::table('pos_ingredients', function (Blueprint $table): void {
            $table->dropColumn('ml_per_unit');
        });
    }
};
