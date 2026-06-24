<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Damage / waste log — one row per batch of stock written off because it was
 * broken, expired, spoiled, spilled or stolen. Each entry points at exactly one
 * catalogue item (a product, an ingredient or a condiment), decrements that
 * item's on-hand quantity, and snapshots its unit cost so the Damage Report can
 * value the loss even after the item's cost later changes. Shared by the Café,
 * Retail and Retail+Crafting business types (everything with stock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_damages', function (Blueprint $table): void {
            $table->id();
            // Human reference (DMG/00001), derived from the id after insert.
            $table->string('reference')->nullable()->unique();
            $table->date('damaged_on');
            // Which catalogue the damaged item lives in: product|ingredient|condiment.
            $table->string('item_type');
            // Exactly one of these is set, matching item_type. Logical refs (no
            // FK) so a deleted catalogue item never blocks deleting history.
            $table->unsignedBigInteger('pos_product_id')->nullable();
            $table->unsignedBigInteger('pos_ingredient_id')->nullable();
            $table->unsignedBigInteger('pos_condiment_id')->nullable();
            $table->decimal('quantity', 12, 3)->default(0);
            // Unit cost snapshot at the moment of damage (0 for condiments, which
            // carry no tracked cost). loss_value = quantity × unit_cost.
            $table->decimal('unit_cost', 12, 3)->default(0);
            $table->decimal('loss_value', 12, 3)->default(0);
            $table->string('reason')->default('other');
            $table->text('note')->nullable();
            // Name of the user who logged it, snapshotted for the report.
            $table->string('recorded_by')->nullable();
            $table->timestamps();

            $table->index('damaged_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_damages');
    }
};
