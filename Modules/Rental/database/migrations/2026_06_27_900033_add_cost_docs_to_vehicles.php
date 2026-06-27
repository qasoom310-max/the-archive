<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost & paperwork for a car — chiefly for cars rented in from outside: what we
 * pay for it (purchase price), plus the vendor invoice and the original
 * agreement, so the accountant can reconcile real (net) revenue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_vehicles', function (Blueprint $table): void {
            $table->decimal('purchase_price', 12, 3)->default(0)->after('deposit');
            $table->string('purchase_invoice')->nullable()->after('purchase_price');
            $table->string('agreement_copy')->nullable()->after('purchase_invoice');
        });
    }

    public function down(): void
    {
        Schema::table('rental_vehicles', function (Blueprint $table): void {
            $table->dropColumn(['purchase_price', 'purchase_invoice', 'agreement_copy']);
        });
    }
};
