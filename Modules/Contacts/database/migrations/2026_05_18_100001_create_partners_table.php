<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contacts module schema: partners (companies & individuals).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_company')->default(false);
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('image_path')->nullable();
            $table->string('street')->nullable();
            $table->string('street2')->nullable();
            $table->string('city')->nullable();
            $table->string('zip')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->timestamps();

            $table->index('name');
            $table->index('country');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};
