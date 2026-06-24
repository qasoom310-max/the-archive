<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rental invoices — a bill, optionally generated from an order. amount_paid is
 * maintained from receipts; status (unpaid/partial/paid) is derived from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('rental_customers')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('rental_orders')->nullOnDelete();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('subtotal', 10, 3)->default(0);
            $table->decimal('discount', 10, 3)->default(0);
            $table->decimal('total', 10, 3)->default(0);
            $table->decimal('amount_paid', 10, 3)->default(0);
            $table->string('status')->default('unpaid'); // unpaid / partial / paid
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('issue_date');
            $table->index('customer_id');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_invoices');
    }
};
