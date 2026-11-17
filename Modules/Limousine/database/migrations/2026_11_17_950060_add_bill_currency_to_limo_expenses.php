<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bill paid in another currency (Saudi riyal, Kuwaiti dinar, UAE dirham,
 * Omani rial, …). The expense itself is always kept in BHD so every report
 * adds up; these three columns remember what the paper said: the currency,
 * the amount before VAT in that currency, and the rate it was converted at.
 * Existing expenses are BHD with nothing to remember.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('limo_expenses') && ! Schema::hasColumn('limo_expenses', 'bill_currency')) {
            Schema::table('limo_expenses', function (Blueprint $table): void {
                $table->string('bill_currency', 3)->default('BHD');
                $table->decimal('bill_amount', 14, 3)->nullable();
                $table->decimal('bill_rate', 14, 6)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('limo_expenses') && Schema::hasColumn('limo_expenses', 'bill_currency')) {
            Schema::table('limo_expenses', function (Blueprint $table): void {
                $table->dropColumn(['bill_currency', 'bill_amount', 'bill_rate']);
            });
        }
    }
};
