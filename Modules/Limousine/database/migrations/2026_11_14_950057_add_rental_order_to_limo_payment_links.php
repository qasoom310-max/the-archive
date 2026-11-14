<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment link can now be raised for a Rent A Car order too. It goes through
 * the same Wanaan portal and the same paid-callback, so it lives in the same
 * table: the row id stays the one idempotency key the portal quotes back, and
 * a rental link and a limousine link can never share one.
 *
 * A rental link has no trip or booking, so those two become optional;
 * `rental_order_id` is a logical reference like they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('limo_payment_links')) {
            return;
        }

        Schema::table('limo_payment_links', function (Blueprint $table): void {
            $table->unsignedBigInteger('leg_id')->nullable()->change();
            $table->unsignedBigInteger('booking_id')->nullable()->change();
        });

        if (! Schema::hasColumn('limo_payment_links', 'rental_order_id')) {
            Schema::table('limo_payment_links', function (Blueprint $table): void {
                $table->unsignedBigInteger('rental_order_id')->nullable()->after('booking_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('limo_payment_links', 'rental_order_id')) {
            Schema::table('limo_payment_links', function (Blueprint $table): void {
                $table->dropIndex(['rental_order_id']);
            });
            Schema::table('limo_payment_links', function (Blueprint $table): void {
                $table->dropColumn('rental_order_id');
            });
        }
    }
};
