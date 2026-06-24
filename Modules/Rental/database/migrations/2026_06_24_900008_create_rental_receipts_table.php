<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rental receipts — a payment recorded against an invoice. Saving/removing a
 * receipt recomputes the invoice's amount_paid + status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('rental_invoices')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('rental_customers')->nullOnDelete();
            $table->date('date')->nullable();
            $table->decimal('amount', 10, 3)->default(0);
            $table->string('method')->default('cash'); // cash / card / transfer / benefit
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('date');
            $table->index('invoice_id');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_receipts');
    }
};
