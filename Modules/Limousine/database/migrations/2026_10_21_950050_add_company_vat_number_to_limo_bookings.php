<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer company's VAT number for this booking, printed on its invoice
 * and receipts. Per booking, like the company reference, and filled in from
 * the customer's last booking when one is picked.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('limo_bookings') || Schema::hasColumn('limo_bookings', 'company_vat_number')) {
            return;
        }

        Schema::table('limo_bookings', function (Blueprint $table): void {
            $table->string('company_vat_number', 50)->nullable()->after('company_reference');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('limo_bookings', 'company_vat_number')) {
            Schema::table('limo_bookings', function (Blueprint $table): void {
                $table->dropColumn('company_vat_number');
            });
        }
    }
};
