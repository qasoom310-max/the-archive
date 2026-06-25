<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-step payment: customer service records the money (advance → Paid), but the
 * payment is only trusted once an accountant confirms it was actually received.
 * These columns hold that confirmation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->boolean('payment_confirmed')->default(false)->after('payment_status');
            $table->unsignedBigInteger('confirmed_by_user_id')->nullable()->after('payment_confirmed');
            $table->dateTime('confirmed_at')->nullable()->after('confirmed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->dropColumn(['payment_confirmed', 'confirmed_by_user_id', 'confirmed_at']);
        });
    }
};
