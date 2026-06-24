<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One overtime session: a worked interval on `work_date` from `start_time` to
 * `end_time` ('HH:MM'). When end ≤ start the session crosses midnight (worked
 * into the next day). Hours are split into Bahrain pay bands at calc time
 * (day 07:00–19:00 ×1.2, night 19:00–07:00 ×1.5) — see OvertimeCalculator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_overtimes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->string('start_time', 5);   // 'HH:MM'
            $table->string('end_time', 5);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_overtimes');
    }
};
