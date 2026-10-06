<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Support\InvoiceServiceDates;

/**
 * When the trips an invoice bills for ran, so the invoices list and the
 * combined invoice can be read by the month of the work, not of the paper.
 * See {@see InvoiceServiceDates}. Filled for every invoice on file.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('limo_invoices')) {
            return;
        }

        if (! Schema::hasColumn('limo_invoices', 'service_date')) {
            Schema::table('limo_invoices', static function (Blueprint $table): void {
                $table->date('service_date')->nullable()->after('due_date')->index();
            });
        }

        InvoiceServiceDates::sync();
    }

    public function down(): void
    {
        if (Schema::hasColumn('limo_invoices', 'service_date')) {
            Schema::table('limo_invoices', static function (Blueprint $table): void {
                $table->dropIndex(['service_date']);
            });
            Schema::table('limo_invoices', static function (Blueprint $table): void {
                $table->dropColumn('service_date');
            });
        }
    }
};
