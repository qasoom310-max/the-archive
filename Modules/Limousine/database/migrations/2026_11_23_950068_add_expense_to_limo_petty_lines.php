<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A receipt on a driver's petty-cash advance that was scanned in Expenses
 * points at that expense, so settling the advance does not write it a second
 * time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('limo_petty_lines') && ! Schema::hasColumn('limo_petty_lines', 'expense_id')) {
            Schema::table('limo_petty_lines', function (Blueprint $table): void {
                $table->unsignedBigInteger('expense_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('limo_petty_lines') && Schema::hasColumn('limo_petty_lines', 'expense_id')) {
            Schema::table('limo_petty_lines', function (Blueprint $table): void {
                $table->dropIndex(['expense_id']);
                $table->dropColumn('expense_id');
            });
        }
    }
};
