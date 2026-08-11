<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a store→shop move to the production run its bottles came from, so the
 * move history can show the date + production reference. A logical ref (no FK):
 * a production may be deleted while its historical transfer stays readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_stock_transfers', function (Blueprint $table): void {
            $table->unsignedBigInteger('pos_production_id')->nullable()->after('pos_product_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('pos_stock_transfers', function (Blueprint $table): void {
            $table->dropColumn('pos_production_id');
        });
    }
};
