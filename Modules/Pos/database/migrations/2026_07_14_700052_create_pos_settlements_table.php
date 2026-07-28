<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settlements — the payouts that bring a delivery sale's money into our own
 * account.
 *
 * On a remote sale the customer's money never reaches us directly: the delivery
 * company collects it (or the customer bank-transfers it), so it sits with a
 * third party until they remit it. Until now the books called that "cash" the
 * moment the order was paid, so nobody could answer "how much has actually
 * landed, and how much is still held by the delivery company?".
 *
 * A settlement is one payout request: it snapshots the orders it covers and the
 * amount expected (their collected total MINUS the delivery fees they deduct),
 * then records what actually arrived — so a wrong or short transfer is visible
 * instead of silently accepted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_settlements', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            // requested → money asked for; received → money in our account.
            $table->string('state')->default('requested')->index();

            $table->dateTime('requested_at')->nullable();
            $table->dateTime('received_at')->nullable();

            // Snapshotted when the payout is requested, so a later edit to an
            // order can't silently rewrite what we asked for.
            $table->decimal('collected_total', 12, 3)->default(0);
            $table->decimal('fees_deducted', 12, 3)->default(0);
            $table->decimal('expected_amount', 12, 3)->default(0);

            $table->decimal('received_amount', 12, 3)->default(0);
            // received − expected. Negative = they sent less than we asked for.
            $table->decimal('difference', 12, 3)->default(0);

            $table->string('method')->nullable();   // bank transfer / cash …
            $table->text('note')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });

        Schema::table('pos_orders', function (Blueprint $table): void {
            // Which payout this order's money was included in (null = still
            // awaiting settlement).
            $table->unsignedBigInteger('pos_settlement_id')->nullable()->index()->after('delivery_charge');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropColumn('pos_settlement_id');
        });

        Schema::dropIfExists('pos_settlements');
    }
};
