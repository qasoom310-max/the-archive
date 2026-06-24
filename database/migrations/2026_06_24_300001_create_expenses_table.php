<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring operating-expense templates — the usual monthly bills the owner
 * pays (Rent, EWA, SIO, LMRA, salaries…). Each is defined once with its usual
 * amount; the actual payment per month is recorded in `expense_payments`.
 *
 * Core (not a module) on purpose: the deploy's plain `migrate --force` always
 * runs core migrations, so this can never sit Pending like a module migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // Free-ish grouping: rent | utilities | government | salaries | other.
            $table->string('category')->default('other');
            // Usual monthly amount (the actual paid amount can differ each month).
            $table->decimal('amount', 12, 3)->default(0);
            // Day of month it's typically due (1–31), optional.
            $table->unsignedTinyInteger('due_day')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
