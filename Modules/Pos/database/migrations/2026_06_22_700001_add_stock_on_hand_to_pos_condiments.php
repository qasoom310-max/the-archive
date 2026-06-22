<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Condiments can now be recipe components (consumed from stock when a product
 * sells), so they need their own on-hand quantity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_condiments', function (Blueprint $table): void {
            $table->decimal('stock_on_hand', 12, 3)->default(0)->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('pos_condiments', function (Blueprint $table): void {
            $table->dropColumn('stock_on_hand');
        });
    }
};
