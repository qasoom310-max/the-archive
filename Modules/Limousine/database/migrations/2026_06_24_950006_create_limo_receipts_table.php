<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Limousine receipts — payments against an invoice. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limo_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('limo_invoices')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('limo_customers')->nullOnDelete();
            $table->date('date')->nullable();
            $table->decimal('amount', 10, 3)->default(0);
            $table->string('method')->default('cash'); // cash / card / benefit / transfer
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('date');
            $table->index('invoice_id');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limo_receipts');
    }
};
