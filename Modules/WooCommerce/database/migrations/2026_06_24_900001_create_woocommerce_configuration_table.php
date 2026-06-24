<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row connection settings for a WooCommerce store's REST API.
 * The consumer key/secret are stored as TEXT holding APP_KEY-encrypted
 * ciphertext (see the model's `encrypted` casts) — never plaintext at
 * rest. Per-database, like every other config table, so each workspace
 * (e.g. Kaleem) points at its own store and others stay untouched.
 *
 * Driver-agnostic columns only (string/text/boolean).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woocommerce_configuration', function (Blueprint $table): void {
            $table->id();
            $table->string('store_url')->nullable();        // https://shop.example.com
            $table->text('consumer_key')->nullable();        // encrypted (ck_...)
            $table->text('consumer_secret')->nullable();     // encrypted (cs_...)
            $table->string('api_version')->default('wc/v3');
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woocommerce_configuration');
    }
};
