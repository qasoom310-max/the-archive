<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A receipt line can name the CAR the money was spent on.
 *
 * Fuel, a wash, a spare part — most of what petty cash buys belongs to one
 * vehicle, and "what does the GMC cost us" is a question the slips can only
 * answer if they say so. Optional, because parking a guest or feeding a driver
 * belongs to no car.
 *
 * `vehicle` is the label snapshot beside the logical ref, the same pattern the
 * queue's legs use: a settled advance is locked history, and renaming or
 * selling the car later must not blank what the slip said.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('limo_petty_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('car_id')->nullable()->after('photo_path')->index();
            $table->string('vehicle')->nullable()->after('car_id');
        });
    }

    public function down(): void
    {
        // SQLite refuses to drop an indexed column, so the index goes first.
        Schema::table('limo_petty_lines', function (Blueprint $table): void {
            $table->dropIndex(['car_id']);
        });

        Schema::table('limo_petty_lines', function (Blueprint $table): void {
            $table->dropColumn(['car_id', 'vehicle']);
        });
    }
};
