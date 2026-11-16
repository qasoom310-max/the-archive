<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VAT on a limousine expense.
 *
 * The office typed one figure per bill, without VAT, so the expenses never
 * matched what was actually paid. An expense now keeps the amount before VAT
 * (what is typed), the rate (none, 10% or 15%), the VAT worked out from it,
 * and the total in `amount`, which is what every report already adds up.
 *
 * Existing expenses become "amount before VAT = amount, no VAT": the figure
 * each one shows does not change until somebody chooses a rate on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('limo_expenses')) {
            return;
        }

        Schema::table('limo_expenses', function (Blueprint $table): void {
            if (! Schema::hasColumn('limo_expenses', 'net_amount')) {
                $table->decimal('net_amount', 12, 3)->default(0)->after('amount');
            }
            if (! Schema::hasColumn('limo_expenses', 'vat_rate')) {
                $table->unsignedTinyInteger('vat_rate')->default(0)->after('net_amount');
            }
            if (! Schema::hasColumn('limo_expenses', 'vat_amount')) {
                $table->decimal('vat_amount', 12, 3)->default(0)->after('vat_rate');
            }
        });

        // Only rows not yet carrying a split, so a second run changes nothing.
        DB::table('limo_expenses')->where('net_amount', 0)->where('vat_amount', 0)->update(['net_amount' => DB::raw('amount')]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('limo_expenses')) {
            return;
        }

        Schema::table('limo_expenses', function (Blueprint $table): void {
            foreach (['net_amount', 'vat_rate', 'vat_amount'] as $column) {
                if (Schema::hasColumn('limo_expenses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
