<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a coupon was emailed to the customer, and to which address.
 *
 * Recorded for the same reason the Service Order records it: the office needs
 * to know whether the customer has been told, without asking them. Kept as the
 * LAST send rather than a log — "has this gone out, and where to" is the
 * question being asked, and a resend replaces the answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('limo_coupons', function (Blueprint $table): void {
            $table->dateTime('sent_at')->nullable()->after('note');
            $table->string('sent_to')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('limo_coupons', function (Blueprint $table): void {
            $table->dropColumn(['sent_at', 'sent_to']);
        });
    }
};
