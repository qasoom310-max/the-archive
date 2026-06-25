<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row Cloudflare Stream connection settings (per database). The API
 * token is stored as TEXT holding APP_KEY-encrypted ciphertext (see the
 * model's `encrypted` cast) — never plaintext at rest. The account id is not
 * a secret (it's in the dashboard URL) so it stays a plain string.
 *
 * Core migration → applied to Main by the deploy's `migrate --force` and to
 * every tenant by `workspaces:migrate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloudflare_stream_configuration', function (Blueprint $table): void {
            $table->id();
            $table->string('account_id')->nullable();
            $table->text('api_token')->nullable();   // encrypted
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloudflare_stream_configuration');
    }
};
