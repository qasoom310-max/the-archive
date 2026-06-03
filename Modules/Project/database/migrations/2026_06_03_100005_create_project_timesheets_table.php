<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_timesheets', function (Blueprint $table): void {
            $table->id();

            // A timesheet belongs to a task; dropping the task removes its
            // logged time.
            $table->foreignId('task_id')->constrained('project_tasks')->cascadeOnDelete();

            // The employee who logged the time — logical ref to `users`.
            $table->unsignedBigInteger('user_id')->nullable();

            $table->date('date');

            // Hours spent, e.g. 2.50. Decimal(15,2) matches the planned_hours
            // column so budgets and actuals share precision.
            $table->decimal('unit_amount', 15, 2)->default(0);

            $table->string('name');

            $table->timestamps();

            $table->index('user_id');
            $table->index(['task_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_timesheets');
    }
};
