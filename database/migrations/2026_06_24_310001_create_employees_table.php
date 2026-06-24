<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff records for HR / payroll. Core (not a module) so the deploy's plain
 * `migrate --force` always runs it. `agreement_path` is the uploaded signed
 * contract (public disk, bucket `employee_agreements`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('position')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('cpr')->nullable();              // Bahrain CPR / ID
            $table->decimal('basic_salary', 12, 3)->default(0);
            $table->date('join_date')->nullable();
            $table->string('agreement_path')->nullable();   // uploaded signed contract
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sequence')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
