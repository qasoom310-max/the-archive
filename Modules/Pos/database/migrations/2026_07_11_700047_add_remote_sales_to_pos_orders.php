<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remote / delivery sales: an order is rung up on the same register but tagged
 * as a `remote` channel (phone / WhatsApp / Instagram / delivery) rather than
 * `shop` (walk-in). Remote orders capture the customer name + delivery address,
 * carry a delivery fee added to the total, and move through a fulfillment
 * queue (new → packed → out for delivery → delivered). Stock is unchanged —
 * remote draws the same shop stock the register sells from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->string('channel')->default('shop')->after('state'); // shop | remote
            $table->string('customer_name')->nullable()->after('customer_phone');
            $table->text('delivery_address')->nullable()->after('customer_name');
            $table->decimal('delivery_fee', 12, 2)->default(0)->after('delivery_address');
            $table->string('fulfillment_status')->nullable()->after('delivery_fee'); // remote only
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropColumn(['channel', 'customer_name', 'delivery_address', 'delivery_fee', 'fulfillment_status']);
        });
    }
};
