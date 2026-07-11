<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A remote / delivery order can carry a delivery reference number — a courier
 * tracking number or an internal delivery-note number — so the order can be
 * matched to the physical delivery. Optional, free text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->string('delivery_reference')->nullable()->after('fulfillment_status');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropColumn('delivery_reference');
        });
    }
};
