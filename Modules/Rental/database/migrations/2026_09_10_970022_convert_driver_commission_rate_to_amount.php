<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The office decided against a fixed commission percentage scale
 * (25/15/10/7/5%) — commission is now a flat amount in Bahraini Dinar,
 * typed freely by the admin. `commission_rate` (decimal 5,2 — enough for a
 * percentage, not a BD figure) becomes `commission_amount` (decimal 8,2),
 * and the column is renamed so the name still says what it holds.
 *
 * Two `Schema::table` calls, not one — renaming and widening the same
 * column in a single blueprint has caused ordering trouble with SQLite
 * elsewhere in this codebase (see the `pos_session_id` drop-index gotcha in
 * CLAUDE.md); doing them as separate statements sidesteps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_drivers', function (Blueprint $table): void {
            $table->renameColumn('commission_rate', 'commission_amount');
        });

        Schema::table('rental_drivers', function (Blueprint $table): void {
            $table->decimal('commission_amount', 8, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('rental_drivers', function (Blueprint $table): void {
            $table->decimal('commission_amount', 5, 2)->nullable()->change();
        });

        Schema::table('rental_drivers', function (Blueprint $table): void {
            $table->renameColumn('commission_amount', 'commission_rate');
        });
    }
};
