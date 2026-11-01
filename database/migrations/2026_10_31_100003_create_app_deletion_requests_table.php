<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requests to delete a staff-app account — from the public web page (Google
 * Play requires one that works without the app) or from inside the app (Apple
 * wants it there). Kept on Main, where the logins live; an admin works through
 * them under Settings → Users. Core migration, so workspaces get an unused
 * empty copy, as with the sign-in tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_deletion_requests')) {
            return;
        }

        Schema::create('app_deletion_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('login', 191);
            $table->string('contact_email', 191)->nullable();
            $table->text('note')->nullable();
            $table->string('source', 10);
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('business', 120)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->string('handled_by', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_deletion_requests');
    }
};
