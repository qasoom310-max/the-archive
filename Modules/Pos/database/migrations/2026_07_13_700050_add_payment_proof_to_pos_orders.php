<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof-of-payment photo for a POS order — e.g. a screenshot of a Benefit /
 * bank-transfer confirmation the cashier attaches in the payment popup so the
 * owner / accountant can later verify the money actually came in. Path on the
 * public disk; optional, and only surfaced when the "Proof of payment" POS
 * feature is enabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->string('payment_proof_path')->nullable()->after('customer_name');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropColumn('payment_proof_path');
        });
    }
};
