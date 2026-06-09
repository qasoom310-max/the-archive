<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional asset-tracking fields on a Chart-of-Accounts account: an
 * attached document (PDF/image — e.g. an invoice or photo), a cost per
 * unit and a number of units. Purely descriptive metadata — they do not
 * participate in any ledger maths; the journal items remain the source of
 * truth for balances.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            // Relative path on the `public` disk (e.g. `account_files/ab12.pdf`),
            // set by FormFileUploadController. Nullable — most ledger accounts
            // carry no attachment.
            $table->string('document_path')->nullable()->after('active');

            // Money per unit. decimal(12,2) matches the rest of the system's
            // currency columns (all currencies display at ≤ 2 dp).
            $table->decimal('cost_per_unit', 12, 2)->nullable()->after('document_path');

            // Whole-number count of units.
            $table->unsignedInteger('units')->nullable()->after('cost_per_unit');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['document_path', 'cost_per_unit', 'units']);
        });
    }
};
