<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per payment link ("partition"). A booking can carry several — a
 * deposit now, the balance later, or one per leg — so the row, NOT the booking,
 * is the idempotency key the WordPress portal and its paid-callback quote back.
 *
 * `leg_id` / `booking_id` / `created_by_user_id` are logical references (no FK):
 * the Limousine records they point at live in the same database, but keeping
 * them unconstrained matches the rest of the module and survives a leg being
 * re-created during an edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limo_payment_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('leg_id')->index();
            $table->unsignedBigInteger('booking_id')->index();
            $table->decimal('amount', 12, 3);
            $table->string('currency', 3)->default('BHD');
            // Filled from the portal's reply; the public page lives at this URL.
            $table->string('token', 64)->nullable()->unique();
            $table->string('url')->nullable();
            // unpaid → pending (customer on Tap) → paid ; or cancelled.
            $table->string('status', 16)->default('unpaid')->index();
            $table->unsignedBigInteger('woo_order_id')->nullable();
            $table->string('transaction_ref')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limo_payment_links');
    }
};
