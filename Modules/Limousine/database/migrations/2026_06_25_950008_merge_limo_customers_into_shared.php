<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unify the limousine customer list into the shared transport-customer store.
 *
 * Rental and Limousine served the same people from two separate tables. We make
 * `rental_customers` the single shared store (the rental side is untouched) and
 * fold `limo_customers` into it: every limo customer is matched to a shared row
 * by phone (or copied in if new), the limo booking / quotation / invoice /
 * receipt rows are repointed to the shared id, and the old table is dropped.
 *
 * Limousine depends on Rental, so `rental_customers` always exists first.
 * Idempotent: once `limo_customers` is gone the migration is a no-op.
 */
return new class extends Migration
{
    /** Tables whose customer_id pointed at limo_customers. */
    private const REFERENCING = ['limo_bookings', 'limo_quotations', 'limo_invoices', 'limo_receipts'];

    public function up(): void
    {
        // Already merged, or the shared store isn't present (Rental absent) —
        // nothing to do either way.
        if (! Schema::hasTable('limo_customers') || ! Schema::hasTable('rental_customers')) {
            return;
        }

        // 1. Map each limo customer to a shared rental_customers id: reuse a row
        //    with the same phone, otherwise copy the customer into the shared
        //    store. (Same-phone = same person, per the chosen merge rule.)
        /** @var array<int, int> $map old limo id => shared id */
        $map = [];
        foreach (DB::table('limo_customers')->orderBy('id')->get() as $row) {
            $phone = is_string($row->phone ?? null) ? trim((string) $row->phone) : '';

            $sharedId = $phone !== ''
                ? DB::table('rental_customers')->where('phone', $phone)->value('id')
                : null;

            if ($sharedId === null) {
                $sharedId = DB::table('rental_customers')->insertGetId([
                    'name' => $row->name,
                    'phone' => $row->phone,
                    'email' => $row->email,
                    'cpr' => $row->cpr,
                    'nationality' => $row->nationality,
                    'address' => $row->address,
                    'active' => $row->active,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $map[(int) $row->id] = (int) $sharedId;
        }

        // 2. Drop the foreign key to limo_customers on every referencing table
        //    FIRST (SQLite rebuilds the table, preserving data + the plain
        //    customer_id column), so the repoint below can't trip the old FK
        //    and limo_customers can be dropped.
        foreach (self::REFERENCING as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->dropForeign(['customer_id']);
                });
            }
        }

        // 3. Repoint customer_id to the shared ids.
        foreach (self::REFERENCING as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($map as $oldId => $sharedId) {
                DB::table($table)->where('customer_id', $oldId)->update(['customer_id' => $sharedId]);
            }
        }

        // 4. Drop the now-redundant per-app table.
        Schema::dropIfExists('limo_customers');
    }

    public function down(): void
    {
        // Recreate the table for schema-reversibility. The merged customer data
        // is not split back out (it now lives in the shared store).
        if (Schema::hasTable('limo_customers')) {
            return;
        }

        Schema::create('limo_customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('cpr')->nullable();
            $table->string('nationality')->nullable();
            $table->text('address')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }
};
