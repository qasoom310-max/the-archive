<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profile page fields:
 *
 *  - `avatar_path` is a path on the `public` disk (e.g.
 *    `avatars/3-2026-05-21.jpg`), nullable for users who haven't
 *    uploaded one. The dropdown + profile both render via
 *    `Storage::disk('public')->url($path)`.
 *
 *  - `new_email` is the staging area for a pending email change.
 *    The profile form writes there; clicking the signed-URL link in
 *    the verification email promotes it to `email` and nulls
 *    `new_email`. Indexed so the verification controller's lookup
 *    `where('new_email', $email)` doesn't table-scan on a busy DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('avatar_path')->nullable()->after('email');
            $table->string('new_email')->nullable()->after('avatar_path');
            $table->index('new_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['new_email']);
            $table->dropColumn(['avatar_path', 'new_email']);
        });
    }
};
