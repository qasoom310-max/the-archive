<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytic_lines', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('analytic_account_id')
                ->constrained('analytic_accounts')->cascadeOnDelete();

            $table->date('date');
            $table->string('name');

            // Signed managerial amount: negative = cost, positive = revenue.
            $table->decimal('amount', 15, 2)->default(0);

            // Quantity dimension (hours for a timesheet line).
            $table->decimal('unit_amount', 15, 2)->default(0);

            // Logical ref to the user the cost is attributed to.
            $table->unsignedBigInteger('user_id')->nullable();

            // Idempotency key of the originating document, e.g.
            // "project.timesheet:42" — unique so re-syncing upserts in place
            // instead of duplicating the cost line.
            $table->string('source_ref')->nullable()->unique();

            $table->timestamps();

            $table->index('analytic_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytic_lines');
    }
};
