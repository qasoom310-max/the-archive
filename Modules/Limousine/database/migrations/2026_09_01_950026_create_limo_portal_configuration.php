<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-database connection settings for the Wanaan WordPress service-order
 * portal. Single row. `shared_secret` holds APP_KEY-encrypted ciphertext (the
 * `encrypted` cast on the model), never plaintext.
 *
 * `enabled` is the master OFF switch: it defaults to false so the push stays
 * dormant on every database until an admin turns it on for wanaan specifically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limo_portal_configuration', function (Blueprint $table): void {
            $table->id();
            $table->string('portal_url')->nullable();
            $table->text('shared_secret')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limo_portal_configuration');
    }
};
