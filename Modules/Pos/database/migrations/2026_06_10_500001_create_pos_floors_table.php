<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant floors (Main floor, Patio, …) — the tabs above the table
 * picker. Each floor holds a set of {@see pos_tables}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_floors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_floors');
    }
};
