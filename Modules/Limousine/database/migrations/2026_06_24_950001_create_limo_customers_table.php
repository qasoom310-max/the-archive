<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Limousine customers. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limo_customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('cpr')->nullable();
            $table->string('nationality')->nullable();
            $table->text('address')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('name');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limo_customers');
    }
};
