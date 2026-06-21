<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System activity log (audit trail): one immutable row per meaningful action
 * — logins, record create/update/delete, user management, settings changes.
 * The actor's name + role are snapshotted so the log still reads correctly
 * after the user is renamed or deleted. Core table, so each workspace
 * database gets its own (logs are scoped to the database they happened in).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id();
            // Logical ref (no FK) so a row survives the user being deleted.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name')->default('System'); // snapshot
            $table->boolean('user_is_admin')->default(false); // snapshot (role badge)
            $table->string('action', 40)->index();
            $table->string('subject')->nullable();      // e.g. "Partner #5"
            $table->text('description')->nullable();     // human-readable detail
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
