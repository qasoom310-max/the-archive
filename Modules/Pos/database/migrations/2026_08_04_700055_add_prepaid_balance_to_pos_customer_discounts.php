<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepaid balance (store credit) on a per-phone customer discount. Orders draw
 * this down at FULL price until it reaches 0; only then does the discount % kick
 * in. Default 0 = no credit, so existing rows behave exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_customer_discounts', function (Blueprint $table): void {
            $table->decimal('prepaid_balance', 12, 2)->default(0)->after('discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('pos_customer_discounts', function (Blueprint $table): void {
            $table->dropColumn('prepaid_balance');
        });
    }
};
