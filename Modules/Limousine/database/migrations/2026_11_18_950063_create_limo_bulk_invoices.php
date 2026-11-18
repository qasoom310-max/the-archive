<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bulk invoice: several of one customer's invoices sent as ONE document,
 * kept on record so the office can follow it and take its payment.
 *
 * An invoice says which bulk invoice it is in on its own row
 * (limo_invoices.bulk_invoice_id). One column holds one value, so an invoice
 * cannot be in two bulk invoices at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('limo_bulk_invoices')) {
            Schema::create('limo_bulk_invoices', function (Blueprint $table): void {
                $table->id();
                $table->string('reference')->nullable()->index();
                $table->unsignedBigInteger('customer_id')->nullable()->index();
                $table->date('issue_date')->nullable();
                $table->date('period_from')->nullable();
                $table->date('period_to')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('limo_invoices') && ! Schema::hasColumn('limo_invoices', 'bulk_invoice_id')) {
            Schema::table('limo_invoices', function (Blueprint $table): void {
                $table->unsignedBigInteger('bulk_invoice_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('limo_invoices') && Schema::hasColumn('limo_invoices', 'bulk_invoice_id')) {
            Schema::table('limo_invoices', function (Blueprint $table): void {
                $table->dropIndex(['bulk_invoice_id']);
                $table->dropColumn('bulk_invoice_id');
            });
        }

        Schema::dropIfExists('limo_bulk_invoices');
    }
};
