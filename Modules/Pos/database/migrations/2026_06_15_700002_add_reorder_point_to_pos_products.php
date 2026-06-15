<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product reorder point: the on-hand level at or below which the product
 * is flagged "low stock". Null = fall back to the global
 * DailyReport::LOW_STOCK_THRESHOLD. Drives the Stock Report buckets + the
 * 6 AM daily report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->decimal('reorder_point', 12, 3)->nullable()->after('unit');
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->dropColumn('reorder_point');
        });
    }
};
