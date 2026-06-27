<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery is now two separate paid services: drop-off (deliver the car, the
 * existing `delivery` flag) and pick-up (collect it back). Each is a flat fee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->boolean('pickup')->default(false)->after('delivery');
        });
    }

    public function down(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->dropColumn('pickup');
        });
    }
};
