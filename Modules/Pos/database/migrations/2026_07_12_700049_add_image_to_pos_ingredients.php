<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A photo of a raw material / ingredient (a bottle, cap, pump, oil…), so the
 * perfume workshop can recognise it at a glance. Path on the public disk;
 * uploaded via the ingredient form's image widget, like a product photo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_ingredients', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('pos_ingredients', function (Blueprint $table): void {
            $table->dropColumn('image_path');
        });
    }
};
