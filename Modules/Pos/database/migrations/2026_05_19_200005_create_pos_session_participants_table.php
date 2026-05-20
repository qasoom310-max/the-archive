<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presence/heartbeat for the single global register: one row per
 * (session, user); `last_activity` is bumped by the terminal's poll so
 * the Manage view can show who is currently working the shared session.
 * FKs are declared at CREATE (SQLite supports that), cascading so a
 * deleted session/user takes its presence rows with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_session_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pos_session_id')->constrained('pos_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_activity')->nullable();
            $table->timestamps();

            $table->unique(['pos_session_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_session_participants');
    }
};
