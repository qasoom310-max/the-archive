<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Petty-cash categories become the owner's list, not the programmer's.
 *
 * The first hardcoded set was a guess; the owner named the real one (fuel,
 * insurance, tolls, parking, maintenance, spare parts, food, office expenses)
 * and wants to add more himself — so the list lives in a table.
 *
 * `expense_category` is how a petty category lands in the expense ledger's
 * fixed taxonomy at settlement. Seeded rows map 1:1; a category the owner
 * invents later files under "other", carrying its own name in the notes.
 *
 * Lines keep storing the category NAME as text, deliberately: a settled
 * advance is locked history, and renaming a category later must not rewrite
 * what a slip said it was for. Existing lines' slugs are converted to names.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('limo_petty_categories')) {
            Schema::create('limo_petty_categories', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->unique();
                $table->string('expense_category', 30)->nullable();
                $table->timestamps();
            });
        }

        foreach ([
            ['Fuel', 'fuel'],
            ['Insurance', 'insurance'],
            ['Tolls', 'tolls'],
            ['Parking', 'parking'],
            ['Maintenance', 'maintenance'],
            ['Spare parts', 'spare_parts'],
            ['Food', 'food'],
            ['Office expenses', 'office'],
        ] as [$name, $slug]) {
            if (! DB::table('limo_petty_categories')->where('name', $name)->exists()) {
                DB::table('limo_petty_categories')->insert([
                    'name' => $name, 'expense_category' => $slug,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // Lines written under the first hardcoded set carry its slugs; they
        // become the names those slugs displayed as.
        if (Schema::hasTable('limo_petty_lines')) {
            foreach ([
                'fuel' => 'Fuel',
                'wash' => 'Car wash & cleaning',
                'parking' => 'Parking',
                'maintenance' => 'Maintenance',
                'other' => 'Other',
            ] as $slug => $name) {
                DB::table('limo_petty_lines')->where('category', $slug)->update(['category' => $name]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('limo_petty_categories');
    }
};
