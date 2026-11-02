<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extras sold with a trip (a child seat, Causeway Plus, a luggage helper).
 *
 * `limo_extras` is the price list the office picks from. What a trip actually
 * got is kept on the trip itself (`limo_legs.extras`, name + price + count) so
 * changing a price later never rewrites a trip already sold, and
 * `extras_total` is that list's sum, already counted inside `net_amount`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('limo_extras')) {
            Schema::create('limo_extras', function (Blueprint $table): void {
                $table->id();
                $table->text('name'); // translatable JSON: {"en": …, "ar": …}
                $table->decimal('price', 10, 3)->default(0);
                $table->boolean('active')->default(true);
                $table->integer('sequence')->default(0);
                $table->timestamps();
            });

            $now = now();
            foreach ([
                ['Child seat', 'كرسي أطفال', 5, 10],
                ['Causeway Plus', 'جسر بلس', 30, 20],
                ['Luggage helper', 'مساعد للشنط', 10, 30],
            ] as [$en, $ar, $price, $sequence]) {
                DB::table('limo_extras')->insert([
                    'name' => json_encode(['en' => $en, 'ar' => $ar], JSON_UNESCAPED_UNICODE),
                    'price' => $price,
                    'active' => true,
                    'sequence' => $sequence,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (! Schema::hasColumn('limo_legs', 'extras')) {
            Schema::table('limo_legs', function (Blueprint $table): void {
                $table->json('extras')->nullable();
                $table->decimal('extras_total', 12, 3)->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('limo_legs', 'extras')) {
            Schema::table('limo_legs', function (Blueprint $table): void {
                $table->dropColumn(['extras', 'extras_total']);
            });
        }

        Schema::dropIfExists('limo_extras');
    }
};
