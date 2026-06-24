<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Limousine invoices — a bill, optionally generated from a booking. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limo_invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('limo_customers')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('limo_bookings')->nullOnDelete();
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
            $table->index('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limo_invoices');
    }
};
