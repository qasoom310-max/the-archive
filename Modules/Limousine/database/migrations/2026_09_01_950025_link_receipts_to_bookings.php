<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A receipt can now say which BOOKING it paid, and what was still owed after.
 *
 * Receipts hung off invoices, which is fine for a billed job but not for the
 * common case: money handed over when the booking is taken, or when the driver
 * gets back. There is no invoice then, and typing one up so a receipt has
 * something to point at is paperwork invented to satisfy a schema.
 *
 * `balance_after` is stored rather than recomputed because a receipt is a record
 * of a moment. Working it out later from today's numbers would make an old
 * receipt quietly change its mind about what the customer owed that day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('limo_receipts', function (Blueprint $table): void {
            $table->unsignedBigInteger('booking_id')->nullable()->after('invoice_id')->index();
            $table->decimal('balance_after', 12, 3)->nullable()->after('amount');
            // Raised by the system when money was taken, rather than typed up
            // afterwards — worth knowing when a receipt is queried.
            $table->boolean('auto')->default(false)->after('method');
        });
    }

    public function down(): void
    {
        Schema::table('limo_receipts', function (Blueprint $table): void {
            $table->dropColumn(['booking_id', 'balance_after', 'auto']);
        });
    }
};
