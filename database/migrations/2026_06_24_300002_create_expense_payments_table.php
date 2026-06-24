<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One actual payment of a recurring expense for a given month — created when
 * the owner marks a bill paid. `period` is the month it covers ('YYYY-MM'),
 * which may differ from `paid_on` (a late payment). `amount` is the real paid
 * amount (EWA/water vary month to month), defaulting from the template.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->string('name');             // snapshot of the bill name
            $table->string('period', 7)->index(); // 'YYYY-MM' the payment covers
            $table->decimal('amount', 12, 3)->default(0);
            $table->date('paid_on');
            $table->text('notes')->nullable();
            $table->timestamps();

            // A given recurring bill is paid at most once per month. (NULL
            // expense_id rows are distinct, leaving room for ad-hoc later.)
            $table->unique(['expense_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_payments');
    }
};
