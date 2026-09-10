<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a quotation was emailed to the customer, and to which address.
 *
 * `status = sent` already said THAT it went; this says when and where, which is
 * the question actually asked when a customer rings to say they never got it.
 * The last send rather than a log, for the same reason as the coupon: "has this
 * gone out, and where to" is the question, and a resend replaces the answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('limo_quotations', function (Blueprint $table): void {
            $table->dateTime('sent_at')->nullable()->after('status');
            $table->string('sent_to')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('limo_quotations', function (Blueprint $table): void {
            $table->dropColumn(['sent_at', 'sent_to']);
        });
    }
};
