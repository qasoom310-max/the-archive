<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer company's VAT number for this rental order, printed on its invoice
 * and receipts. Per order, and filled in from
 * the customer's last order when one is picked.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_orders') || Schema::hasColumn('rental_orders', 'company_vat_number')) {
            return;
        }

        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->string('company_vat_number', 50)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_orders', 'company_vat_number')) {
            Schema::table('rental_orders', function (Blueprint $table): void {
                $table->dropColumn('company_vat_number');
            });
        }
    }
};
