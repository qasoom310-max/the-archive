<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workspace registry — the list of "databases" an admin can switch
 * between. Lives in the Main DB (the `App\Models\Workspace` model pins the
 * `landlord` connection), so it is always reachable regardless of which
 * tenant connection is active.
 *
 * `database` = the SQLite file path for a tenant workspace; null for the
 * built-in Main workspace (which uses the app's default connection).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('database')->nullable();
            $table->boolean('is_main')->default(false);
            $table->unsignedBigInteger('owner_user_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
