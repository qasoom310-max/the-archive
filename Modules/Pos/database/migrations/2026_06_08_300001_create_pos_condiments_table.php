<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_condiments', function (Blueprint $table): void {
            $table->id();
            // Translatable JSON envelope ({"en":..,"ar":..}) — TEXT so both
            // locales fit. Spatie HasTranslations writes/reads the JSON.
            $table->text('name');
            // Per-unit surcharge added to every line it's attached to. 0 = free
            // (a pure kitchen instruction like "No ice").
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_condiments');
    }
};
