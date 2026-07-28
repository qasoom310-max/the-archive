<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bank transfer reference a payout arrived under.
 *
 * The delivery company often settles several of our payout requests with ONE
 * transfer, so every payout confirmed together shares this reference. That is
 * what makes a line on the bank statement traceable back to the exact orders it
 * covered — the whole point of the reconciliation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_settlements', function (Blueprint $table): void {
            $table->string('receipt_reference')->nullable()->index()->after('method');
        });
    }

    public function down(): void
    {
        Schema::table('pos_settlements', function (Blueprint $table): void {
            $table->dropColumn('receipt_reference');
        });
    }
};
