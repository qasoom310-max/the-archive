<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The photo of the bill an expense was scanned from, kept with the expense so
 * the figures can always be checked against the paper. A path on the private
 * disk; null for an expense that was typed in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('limo_expenses') && ! Schema::hasColumn('limo_expenses', 'bill_path')) {
            Schema::table('limo_expenses', function (Blueprint $table): void {
                $table->string('bill_path')->nullable()->after('notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('limo_expenses') && Schema::hasColumn('limo_expenses', 'bill_path')) {
            Schema::table('limo_expenses', function (Blueprint $table): void {
                $table->dropColumn('bill_path');
            });
        }
    }
};
