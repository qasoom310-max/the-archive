<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One day an employee was away. `type` = 'absent' (unpaid by default → deducts
 * from salary at basic ÷ 30) or 'sick' (paid by default → no deduction). The
 * `paid` flag is the source of truth for the deduction so either can be
 * overridden per day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_absences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('date');
            $table->string('type')->default('absent');  // absent | sick
            $table->boolean('paid')->default(false);     // unpaid absence deducts
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_absences');
    }
};
