<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add a "Benefit" payment method (Bahrain's BENEFIT debit network) and order
 * the method buttons Benefit · Card · Cash. Benefit is an ordinary method —
 * it behaves exactly like Card (`is_cash = false`), no special handling.
 * Idempotent: only creates Benefit if absent, and re-asserts the display order
 * by `sequence`. Does not create/alter Card or Cash beyond their sequence.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_payment_methods')) {
            return;
        }

        if (! DB::table('pos_payment_methods')->where('name', 'Benefit')->exists()) {
            $now = now();
            DB::table('pos_payment_methods')->insert([
                'name' => 'Benefit',
                'is_cash' => false,
                'sequence' => 10,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Display order: Benefit, Card, Cash.
        DB::table('pos_payment_methods')->where('name', 'Benefit')->update(['sequence' => 10]);
        DB::table('pos_payment_methods')->where('name', 'Card')->update(['sequence' => 20]);
        DB::table('pos_payment_methods')->where('name', 'Cash')->update(['sequence' => 30]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_payment_methods')) {
            return;
        }

        DB::table('pos_payment_methods')->where('name', 'Benefit')->delete();

        // Restore the original order (Cash, Card).
        DB::table('pos_payment_methods')->where('name', 'Cash')->update(['sequence' => 10]);
        DB::table('pos_payment_methods')->where('name', 'Card')->update(['sequence' => 20]);
    }
};
