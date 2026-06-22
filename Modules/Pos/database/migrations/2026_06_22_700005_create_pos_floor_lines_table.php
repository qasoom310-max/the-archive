<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Floor-plan divider lines — the "walls" an admin drops between rows/columns
 * on the canvas to carve a floor into zones. `orientation` = 'v' (vertical,
 * at a column boundary) or 'h' (horizontal, at a row boundary); `position` =
 * the grid-boundary index (1..N) the line sits on. Per-floor; full-span.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_floor_lines', function (Blueprint $table): void {
            $table->id();
            $table->integer('pos_floor_id')->index();
            $table->string('orientation', 2); // 'v' | 'h'
            $table->integer('position');
            $table->timestamps();

            $table->unique(['pos_floor_id', 'orientation', 'position'], 'pos_floor_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_floor_lines');
    }
};
