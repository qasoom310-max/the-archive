<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-order snapshot of the prepaid-credit draw:
 *   - customer_discount_id — which discount row the credit belongs to (so the
 *     wallet is decremented on the right one at checkout);
 *   - credit_applied       — how much prepaid credit this order consumes;
 *   - credit_consumed       — idempotency guard so finalising can't double-spend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_discount_id')->nullable()->after('customer_discount_total');
            $table->decimal('credit_applied', 12, 2)->default(0)->after('customer_discount_id');
            $table->boolean('credit_consumed')->default(false)->after('credit_applied');
            $table->index('customer_discount_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropIndex(['customer_discount_id']);
            $table->dropColumn(['customer_discount_id', 'credit_applied', 'credit_consumed']);
        });
    }
};
