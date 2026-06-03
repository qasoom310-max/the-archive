<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_stages', function (Blueprint $table): void {
            $table->id();

            // Stages belong to a project; dropping the project takes its
            // columns with it.
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            $table->string('name');

            // Left-to-right column order on the Kanban board.
            $table->unsignedInteger('sequence')->default(0);

            $table->timestamps();

            $table->index(['project_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_stages');
    }
};
