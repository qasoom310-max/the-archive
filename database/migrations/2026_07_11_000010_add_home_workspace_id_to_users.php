<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user can be LOCKED to a single workspace (database). When set, the tenancy
 * layer forces every request into that workspace, hides the database switcher,
 * and refuses any switch — so a "Kaleem-only" admin can never reach another
 * business's data. Null (the default) = a normal, unrestricted user.
 *
 * A logical reference to `workspaces.id` (the landlord table lives in Main); no
 * DB foreign key, because the same column also exists in tenant databases where
 * that table isn't present.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('home_workspace_id')->nullable()->after('is_accountant');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('home_workspace_id');
        });
    }
};
