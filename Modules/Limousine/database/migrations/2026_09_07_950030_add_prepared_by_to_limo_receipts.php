<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who made the receipt, printed on it as "Prepared by" — the pre-printed pad
 * this document replaced had exactly that line, filled in by hand.
 *
 * A name SNAPSHOT rather than a foreign key, for the same reason
 * `confirmed_by` is one: the receipt is a financial document and must still
 * read correctly after the account that raised it is renamed or removed.
 *
 * Deliberately NOT backfilled. We do not know who typed the historical rows,
 * and putting a name on a receipt that did not raise it would be a lie on a
 * financial document — those simply print no line.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('limo_receipts', 'prepared_by')) {
            return;
        }

        Schema::table('limo_receipts', function (Blueprint $table): void {
            $table->string('prepared_by')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('limo_receipts', 'prepared_by')) {
            return;
        }

        Schema::table('limo_receipts', function (Blueprint $table): void {
            $table->dropColumn('prepared_by');
        });
    }
};
