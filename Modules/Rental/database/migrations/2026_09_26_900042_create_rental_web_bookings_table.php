<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bookings taken on the WordPress website, waiting to be turned into rental
 * orders — the list the old system called "Web Bookings".
 *
 * `payload` keeps the WHOLE request exactly as it arrived. The columns beside
 * it are the ones we read and act on, but a booking is a customer's words about
 * money and dates: a field the website starts sending that the ERP does not yet
 * map must not be thrown away between the customer typing it and somebody
 * asking what they asked for.
 *
 * `source_reference` is the website's own id for the booking and is UNIQUE, so
 * a plugin that retries — or a callback delivered twice — cannot put the same
 * booking on the list twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_web_bookings', function (Blueprint $table): void {
            $table->id();

            // The WooCommerce order and line item it came from, "14113-42".
            // UNIQUE, and that is the whole point: the existing plugin sends on
            // `woocommerce_thankyou`, which fires again every time the customer
            // reloads the order-received page — which is why one order appears
            // nine times over on the old system's pending list. Here the second
            // delivery updates the first row instead of adding another.
            $table->string('source_reference')->unique();

            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('town')->nullable();

            // What was asked for, as the website worded it. Free text on
            // purpose: the site sells a PRODUCT, not a car in our fleet — the
            // live site's is literally "Wanaan Booking" — so the match to a
            // real vehicle is a decision a person makes, not a lookup.
            $table->string('product')->nullable();
            $table->unsignedInteger('quantity')->default(1);

            $table->string('pickup_location')->nullable();
            $table->string('dropoff_location')->nullable();
            $table->dateTime('pickup_at')->nullable();
            $table->dateTime('dropoff_at')->nullable();

            // What the customer paid on the website, and whether they did. Not
            // the price WE will charge: an order raised from this is priced on
            // our own rates, and these are what they were told on the site.
            $table->decimal('subtotal', 12, 3)->nullable();
            $table->decimal('total', 12, 3)->nullable();
            $table->string('payment_mode')->nullable();
            $table->string('payment_status', 20)->nullable();

            $table->text('notes')->nullable();

            // pending → complete. Indexed: the screen's two tabs are this.
            $table->string('status', 20)->default('pending')->index();

            // The order raised from it, once somebody has. A logical ref (no
            // FK) like every other cross-screen link in this module, so a
            // deleted order leaves the booking readable rather than vanishing.
            $table->unsignedBigInteger('rental_order_id')->nullable()->index();
            $table->timestamp('completed_at')->nullable();

            $table->json('payload')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_web_bookings');
    }
};
