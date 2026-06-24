<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time-code store for the admin 2FA step. A regular admin (super admins
 * are exempt) must confirm an emailed 6-digit code before deleting/editing a
 * user or a database. One live challenge per (user, action); the code is
 * stored as a SHA-256 hash, never in clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_otp_challenges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('action', 60);
            $table->string('code_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['user_id', 'action'], 'admin_otp_user_action_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_otp_challenges');
    }
};
