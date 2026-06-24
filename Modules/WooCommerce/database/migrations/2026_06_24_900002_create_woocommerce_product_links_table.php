<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maps a POS product to its counterpart product on the WooCommerce store,
 * so a later edit UPDATES the same remote product instead of creating a
 * duplicate. `pos_product_id` is a logical ref (no FK — the row survives a
 * product delete so the listing can still be unpublished). `woo_id` is the
 * remote WooCommerce product id, null until the first successful push.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woocommerce_product_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('pos_product_id')->unique();
            $table->unsignedBigInteger('woo_id')->nullable()->index();
            $table->string('last_status')->nullable();   // queued | synced | unpublished | failed
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woocommerce_product_links');
    }
};
