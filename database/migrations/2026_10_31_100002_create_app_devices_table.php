<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The phones the staff app is signed in on, for its notifications — one row
 * per phone (the Expo push token), owned by the account in THIS database (the
 * reminders run per database, so they find their recipients here). Core
 * migration: Main via `migrate`, every workspace via `workspaces:migrate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_devices')) {
            return;
        }

        Schema::create('app_devices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('token', 191)->unique();
            $table->string('platform', 10)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_devices');
    }
};
