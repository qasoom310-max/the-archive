<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables on a floor. `pos_floor_id` is a plain indexed logical ref (POS
 * keeps no hard FKs across its own catalogues). `seats` = capacity (the
 * denominator in the floor plan's "2/4"); `shape` = square|round so the
 * floor plan can render round tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_tables', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('pos_floor_id')->index();
            $table->string('name');
            $table->unsignedInteger('seats')->default(4);
            $table->string('shape', 16)->default('square');
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_tables');
    }
};
