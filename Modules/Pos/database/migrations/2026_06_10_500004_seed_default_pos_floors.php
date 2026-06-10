<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seed the default restaurant floors (Patio / Ground floor / First floor) so
 * the table form's Floor picker isn't empty on a fresh install. Idempotent —
 * inserts each only when a floor of that name doesn't already exist, so it
 * never duplicates and never overwrites a floor an admin renamed/resequenced.
 *
 * Runs as a migration (not the seeder) because the deploy applies POS
 * migrations automatically but does NOT run PosSeeder.
 */
return new class extends Migration
{
    /** @var list<array{name: string, sequence: int}> */
    private array $floors = [
        ['name' => 'Patio', 'sequence' => 10],
        ['name' => 'Ground floor', 'sequence' => 20],
        ['name' => 'First floor', 'sequence' => 30],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('pos_floors')) {
            return;
        }

        $now = now();

        foreach ($this->floors as $floor) {
            $exists = DB::table('pos_floors')->where('name', $floor['name'])->exists();
            if ($exists) {
                continue;
            }

            DB::table('pos_floors')->insert([
                'name' => $floor['name'],
                'sequence' => $floor['sequence'],
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_floors')) {
            return;
        }

        DB::table('pos_floors')
            ->whereIn('name', array_column($this->floors, 'name'))
            ->delete();
    }
};
