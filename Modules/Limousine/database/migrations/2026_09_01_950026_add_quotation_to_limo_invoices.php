<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice remembers the quotation it came from.
 *
 * The chain is quote → invoice → trip, so the trip is built AFTER the invoice
 * exists — and the thing that knows the journey (the legs, the route, the
 * hours) is the quotation, not the invoice, which holds only totals. Without
 * this the invoice would have nothing to build a trip from and the office would
 * be retyping a journey it had already priced.
 *
 * A logical ref, like the rest of this module: an invoice outlives the
 * quotation it was raised from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('limo_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('quotation_id')->nullable()->after('booking_id');
            $table->index('quotation_id');
        });
    }

    public function down(): void
    {
        // SQLite refuses to drop an indexed column, so the index goes first in
        // its own statement.
        Schema::table('limo_invoices', function (Blueprint $table): void {
            $table->dropIndex(['quotation_id']);
        });

        Schema::table('limo_invoices', function (Blueprint $table): void {
            $table->dropColumn('quotation_id');
        });
    }
};
