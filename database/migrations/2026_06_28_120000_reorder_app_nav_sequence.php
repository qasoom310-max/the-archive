<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Top-bar app order: Rent A Car · Limousine · Purchases · Accounting (then the
 * rest). The nav reads ir_module.sequence, which is only seeded on a module's
 * first registration — so reorder existing installs directly here. Core
 * migration → applied to Main and every workspace via workspaces:migrate.
 */
return new class extends Migration
{
    /** @var array<string, int> */
    private const ORDER = [
        'rental' => 11,
        'limousine' => 12,
        'purchases' => 13,
        'accounting' => 14,
        'contacts' => 15,
    ];

    public function up(): void
    {
        if (! Schema::hasTable('ir_module')) {
            return;
        }

        foreach (self::ORDER as $name => $sequence) {
            DB::table('ir_module')->where('name', $name)->update(['sequence' => $sequence]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ir_module')) {
            return;
        }

        foreach (['contacts' => 10, 'purchases' => 40, 'accounting' => 50, 'rental' => 70, 'limousine' => 75] as $name => $sequence) {
            DB::table('ir_module')->where('name', $name)->update(['sequence' => $sequence]);
        }
    }
};
