<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `station` is the KDS routing key for a category — `kitchen`, `shisha`,
 * or null (= not displayed on any KDS, e.g. drinks served straight off
 * the counter). When a sale is finalised, each line's product → category
 * → station decides whether the line becomes a KDS ticket and which
 * screen it lands on.
 *
 * Indexed so the KDS query (lines whose product's category's station =
 * $station) can use it cheaply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_categories', function (Blueprint $table): void {
            $table->string('station', 16)->nullable()->after('parent_id');
            $table->index('station');
        });
    }

    public function down(): void
    {
        Schema::table('pos_categories', function (Blueprint $table): void {
            $table->dropIndex(['station']);
            $table->dropColumn('station');
        });
    }
};
