<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_tasks', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            // Stage may be null transiently (e.g. an unstaged backlog item).
            // Deleting a stage nulls its tasks rather than destroying them.
            $table->foreignId('stage_id')->nullable()
                ->constrained('project_stages')->nullOnDelete();

            // Self-referential sub-tasks. Deleting a parent promotes its
            // children to top-level (null) rather than cascading them away.
            $table->foreignId('parent_id')->nullable()
                ->constrained('project_tasks')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // Assigned employee — a LOGICAL ref to `users` (no hard FK):
            // users are a cross-cutting concern and SQLite can't add FKs via
            // ALTER, so the project mirrors the POS `pos_orders.user_id`
            // convention (indexed bigint, belongsTo on the model).
            $table->unsignedBigInteger('user_id')->nullable();

            // Priority "star".
            $table->boolean('priority')->default(false);

            // normal | blocked | done — Modules\Project\Enums\KanbanState.
            $table->string('kanban_state', 16)->default('normal');
            $table->string('blocked_reason')->nullable();

            // Time budget the task's timesheets burn against.
            $table->decimal('planned_hours', 15, 2)->default(0);

            // Vertical order within a stage column.
            $table->unsignedInteger('sequence')->default(0);

            $table->timestamps();

            $table->index(['project_id', 'stage_id', 'sequence']);
            $table->index('parent_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_tasks');
    }
};
