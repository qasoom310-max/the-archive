<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice can be a charge that is not a journey.
 *
 * A late-payment fee is money the customer owes, so it belongs in the same
 * place every other debt lives — otherwise it sits in a side table that the
 * statement, the outstanding balance and the payment screens all have to be
 * taught about separately, and one of them is always forgotten.
 *
 * The label is what the document and the statement print instead of a route:
 * "Late payment charge — June 2026". Null on an ordinary invoice, which reads
 * its lines from the trip or the quote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('limo_invoices', function (Blueprint $table): void {
            $table->string('charge_label')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('limo_invoices', function (Blueprint $table): void {
            $table->dropColumn('charge_label');
        });
    }
};
