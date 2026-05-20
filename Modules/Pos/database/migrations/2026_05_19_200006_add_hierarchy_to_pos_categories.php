<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nested categories + visual button + URL slug. `parent_id` is a plain
 * nullable indexed *logical ref* (no DB FK): SQLite can't ALTER-add a
 * foreign key, a self-FK is impossible there anyway, and this matches
 * the codebase precedent (`pos_orders.user_id`, `stock_moves.product_id`).
 * Cycle-safety is enforced in the model. `slug` gets a unique index
 * (SQLite treats NULLs as distinct, so unset rows don't collide).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_categories', function (Blueprint $table): void {
            $table->unsignedBigInteger('parent_id')->nullable()->after('name');
            $table->string('slug')->nullable()->after('parent_id');
            $table->string('image')->nullable()->after('slug');

            $table->index('parent_id');
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('pos_categories', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->dropIndex(['parent_id']);
            $table->dropColumn(['parent_id', 'slug', 'image']);
        });
    }
};
