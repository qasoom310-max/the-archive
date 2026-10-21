<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A company's VAT number belongs to the CUSTOMER, not to each job (the owner,
 * 2026-10-21): `rental_customers.vat_number`, shared by Rent A Car and
 * Limousine, printed on their invoices and receipts.
 *
 * The same morning it briefly lived on each limousine booking and rental order
 * (`company_vat_number`). Anything typed there is carried onto its customer
 * (newest first, never over a number the customer already has), then those
 * two columns are dropped.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const OLD = ['limo_bookings', 'rental_orders'];

    public function up(): void
    {
        if (! Schema::hasTable('rental_customers')) {
            return;
        }

        if (! Schema::hasColumn('rental_customers', 'vat_number')) {
            Schema::table('rental_customers', function (Blueprint $table): void {
                $table->string('vat_number', 50)->nullable()->after('cr_number');
            });
        }

        foreach (self::OLD as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_vat_number')) {
                continue;
            }

            DB::table($table)->whereNotNull('company_vat_number')->where('company_vat_number', '!=', '')
                ->whereNotNull('customer_id')->orderByDesc('id')
                ->get(['customer_id', 'company_vat_number'])
                ->each(static function (object $row): void {
                    DB::table('rental_customers')->where('id', $row->customer_id)
                        ->where(static fn ($q) => $q->whereNull('vat_number')->orWhere('vat_number', ''))
                        ->update(['vat_number' => trim((string) $row->company_vat_number)]);
                });

            Schema::table($table, function (Blueprint $t): void {
                $t->dropColumn('company_vat_number');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rental_customers') && Schema::hasColumn('rental_customers', 'vat_number')) {
            Schema::table('rental_customers', function (Blueprint $table): void {
                $table->dropColumn('vat_number');
            });
        }
    }
};
