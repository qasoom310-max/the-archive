<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit held for a customer whose paid trip was cancelled too late to refund.
 *
 * Cancelling inside the 48-hour window earns no refund, but the money is not
 * simply kept: the customer gets a coupon for what they paid, good for a year.
 * It is spent against future trips and — this is the part that needs its own
 * table — it can be spent a bit at a time. A 100 BD coupon may cover 15 here and
 * 15 there until it runs out, so the balance is the issued amount minus every
 * redemption rather than a "used" flag.
 *
 * Redemptions are rows, not a running total on the coupon, so the history is
 * answerable: which trips consumed it, when, and by whom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limo_coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();

            // Where the credit came from — the cancelled trip and its booking,
            // so a customer quoting either can be traced.
            $table->unsignedBigInteger('limo_booking_id')->nullable()->index();
            $table->unsignedBigInteger('limo_leg_id')->nullable()->index();
            $table->string('leg_reference')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable()->index();

            $table->decimal('amount', 12, 3)->default(0);
            // A year from the booking that paid for it.
            $table->dateTime('expires_at')->nullable()->index();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('limo_coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('limo_coupon_id')->index();
            // The booking the credit was spent on.
            $table->unsignedBigInteger('limo_booking_id')->nullable()->index();
            $table->string('booking_reference')->nullable();
            $table->decimal('amount', 12, 3)->default(0);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });

        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            // none | refunded | coupon — what the customer got, decided by the
            // 48-hour rule and (for a full refund) by the person cancelling.
            $table->string('refund_outcome')->nullable();
            $table->decimal('refund_amount', 12, 3)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->dropColumn(['cancelled_at', 'cancellation_reason', 'refund_outcome', 'refund_amount']);
        });

        Schema::dropIfExists('limo_coupon_redemptions');
        Schema::dropIfExists('limo_coupons');
    }
};
