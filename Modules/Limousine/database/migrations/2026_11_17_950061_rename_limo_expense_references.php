<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Limousine expenses are numbered RF/L/EXP123 (the owner's format: the L is
 * for Limousine, so Rent A Car can have its own series later).
 *
 * The few expenses numbered Rf/Exp123 in the short time that format was live
 * are renamed to match, keeping their number. Nothing else about them
 * changes. The older EXP/00001 references are left exactly as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('Rf/Exp', 'RF/L/EXP');
    }

    public function down(): void
    {
        $this->rename('RF/L/EXP', 'Rf/Exp');
    }

    private function rename(string $from, string $to): void
    {
        if (! Schema::hasTable('limo_expenses')) {
            return;
        }

        foreach (DB::table('limo_expenses')->where('reference', 'like', $from . '%')->get(['id', 'reference']) as $row) {
            $digits = substr((string) $row->reference, strlen($from));

            // Only the system's own numbers; anything typed by hand is left.
            if ($digits === '' || ! ctype_digit($digits) || ! str_starts_with((string) $row->reference, $from)) {
                continue;
            }
            if (DB::table('limo_expenses')->where('reference', $to . $digits)->exists()) {
                continue;
            }

            DB::table('limo_expenses')->where('id', $row->id)->update(['reference' => $to . $digits]);
        }
    }
};
