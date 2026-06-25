<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flesh the rental order out into a full hire contract: an order date, the
 * customer's phone, an additional authorised driver + licence, the vehicle's
 * mileage at handover, a pick-up time, VAT (flat 10% on rentals), delivery
 * charges, advance paid + balance due, a payment type and a handover photo.
 *
 * `subtotal` stays the pre-tax "Amount" and `total` becomes the Net Total
 * (amount − discount + VAT + delivery); `notes` is the Comments field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->date('order_date')->nullable()->after('reference');
            $table->string('phone')->nullable()->after('customer_id');
            $table->string('additional_driver')->nullable()->after('driver_id');
            $table->string('additional_driver_license')->nullable()->after('additional_driver');
            $table->unsignedInteger('pickup_mileage')->nullable()->after('vehicle_id');
            $table->string('hired_time')->nullable()->after('end_date');
            $table->decimal('vat_rate', 6, 3)->default(10)->after('discount');
            $table->decimal('vat_amount', 10, 3)->default(0)->after('vat_rate');
            $table->decimal('delivery_charges', 10, 3)->default(0)->after('vat_amount');
            $table->decimal('advance_amount', 10, 3)->default(0)->after('total');
            $table->decimal('balance', 10, 3)->default(0)->after('advance_amount');
            $table->string('payment_type')->nullable()->after('balance');
            $table->string('image_path')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'order_date', 'phone', 'additional_driver', 'additional_driver_license',
                'pickup_mileage', 'hired_time', 'vat_rate', 'vat_amount', 'delivery_charges',
                'advance_amount', 'balance', 'payment_type', 'image_path',
            ]);
        });
    }
};
