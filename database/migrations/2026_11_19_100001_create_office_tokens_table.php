<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keys for the AI office (a separate app) to READ the ERP through
 * /api/office/v1. Not staff logins: a key belongs to no person, carries no
 * permissions, and lists the business databases it may read. Kept on Main;
 * core migration, so workspaces get an unused empty copy, as with the
 * sign-in tokens. Only a SHA-256 hash of each key is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('office_tokens')) {
            return;
        }

        Schema::create('office_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('token_hash', 64)->unique();
            $table->json('workspace_ids');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_tokens');
    }
};
