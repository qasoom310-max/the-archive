<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The online store (a separate website) reads this database's products and
 * sends its orders in. Three tables, all owned by this module so nothing in
 * the POS tables changes:
 *
 *  - storefront_configuration — one row: the switch, the shared secret the
 *    website signs with (encrypted), and the shop details it shows.
 *  - storefront_product_details — what only the website needs per product:
 *    description and fragrance notes in English and Arabic, featured, order.
 *  - storefront_orders — the inbox. A website order waits here until a member
 *    of staff accepts it into the register (or rejects it); nothing touches
 *    stock or money until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('storefront_configuration')) {
            Schema::create('storefront_configuration', function (Blueprint $table): void {
                $table->id();
                $table->boolean('enabled')->default(false);
                $table->text('shared_secret')->nullable();
                $table->string('whatsapp_number', 32)->nullable();
                $table->decimal('delivery_charge', 10, 3)->default(0);
                $table->decimal('free_delivery_over', 10, 3)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('storefront_product_details')) {
            Schema::create('storefront_product_details', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('pos_product_id')->unique();
                $table->json('description')->nullable();
                $table->json('notes_top')->nullable();
                $table->json('notes_heart')->nullable();
                $table->json('notes_base')->nullable();
                $table->boolean('featured')->default(false);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('storefront_orders')) {
            Schema::create('storefront_orders', function (Blueprint $table): void {
                $table->id();
                $table->string('source_reference', 64)->unique();
                $table->string('status', 16)->default('new')->index();
                $table->string('customer_name', 120);
                $table->string('customer_phone', 32);
                $table->text('delivery_address');
                $table->text('notes')->nullable();
                $table->json('lines');
                $table->decimal('subtotal', 10, 3)->default(0);
                $table->decimal('delivery_charge', 10, 3)->default(0);
                $table->decimal('total', 10, 3)->default(0);
                $table->unsignedBigInteger('pos_order_id')->nullable();
                $table->unsignedBigInteger('handled_by')->nullable();
                $table->timestamp('handled_at')->nullable();
                $table->string('reject_reason', 255)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_orders');
        Schema::dropIfExists('storefront_product_details');
        Schema::dropIfExists('storefront_configuration');
    }
};
