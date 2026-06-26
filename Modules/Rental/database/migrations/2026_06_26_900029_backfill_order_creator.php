<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders made before creator-tracking existed have no created_by. Attribute those
 * legacy rows to the workspace owner (the super-admin, else the oldest account)
 * so the list isn't blank. Only touches rows that are still null.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_orders') || ! Schema::hasTable('users')) {
            return;
        }

        $ownerId = DB::table('users')->where('is_super_admin', true)->orderBy('id')->value('id')
            ?? DB::table('users')->orderBy('id')->value('id');

        if ($ownerId === null) {
            return;
        }

        DB::table('rental_orders')->whereNull('created_by_user_id')->update([
            'created_by_user_id' => $ownerId,
        ]);
    }

    public function down(): void
    {
        // No-op: we cannot tell which rows were backfilled vs genuinely set.
    }
};
