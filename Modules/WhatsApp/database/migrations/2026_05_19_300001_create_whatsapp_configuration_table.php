<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row connection settings for the Meta WhatsApp Business Cloud API.
 * Secrets (access_token, app_secret, webhook_verify_token) are stored as
 * TEXT because they hold APP_KEY-encrypted ciphertext (see the model's
 * `encrypted` casts) — never plaintext at rest. Driver-agnostic columns
 * only (string/text/boolean) so SQLite/MySQL/PostgreSQL all migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_configuration', function (Blueprint $table): void {
            $table->id();
            $table->string('phone_number_id')->nullable();       // Meta "from" phone number id
            $table->string('business_account_id')->nullable();   // WABA id
            $table->text('access_token')->nullable();             // encrypted
            $table->text('app_secret')->nullable();               // encrypted — webhook signature
            $table->text('webhook_verify_token')->nullable();     // encrypted — GET handshake
            $table->string('api_version')->default('v21.0');
            $table->string('from_phone_label')->nullable();       // human label for the UI
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_configuration');
    }
};
