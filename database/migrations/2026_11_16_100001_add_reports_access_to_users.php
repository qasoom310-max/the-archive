<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a named user open the owner's reports without being an admin: Daily
 * Summary, Profit & Expenses, Car income, Payroll (and the employee records
 * behind it). Granted at the owner's request on 2026-11-16 to Prejitha, the
 * accountant, matched by a name or e-mail starting "prejit". Core migration,
 * so it reaches Main AND every workspace; a no-op where she has no account.
 */
return new class extends Migration
{
    private const NAME_PREFIXES = ['prejit'];

    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('users', 'reports_access')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('reports_access')->default(false);
            });
        }

        $granted = DB::table('users')
            ->where(function ($q): void {
                foreach (self::NAME_PREFIXES as $prefix) {
                    $q->orWhereRaw('LOWER(TRIM(name)) LIKE ?', [$prefix . '%'])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$prefix . '%']);
                }
            });

        $names = (clone $granted)->pluck('name')->all();
        $granted->update(['reports_access' => true]);

        if ($names !== []) {
            Log::info('Owner reports granted to: ' . implode(', ', $names) . ' in ' . DB::connection()->getDatabaseName());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'reports_access')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('reports_access');
            });
        }
    }
};
