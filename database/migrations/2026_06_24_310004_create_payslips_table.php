<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A finalised salary slip for one employee + month — a SNAPSHOT of the
 * computed figures at the moment it was marked paid, so a later salary change
 * never rewrites history. The month's total paid `net` flows into the
 * Profit & Expenses P&L as payroll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('period', 7)->index();  // 'YYYY-MM'
            $table->decimal('basic', 12, 3)->default(0);
            $table->decimal('overtime_pay', 12, 3)->default(0);
            $table->decimal('absence_deduction', 12, 3)->default(0);
            $table->decimal('other_deductions', 12, 3)->default(0);
            $table->decimal('allowances', 12, 3)->default(0);
            $table->decimal('net', 12, 3)->default(0);
            $table->date('paid_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }
};
