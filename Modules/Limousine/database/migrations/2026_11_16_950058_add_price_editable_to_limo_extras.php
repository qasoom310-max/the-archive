<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An extra whose price is only a starting point: the trip form fills it in
 * and the office types what this trip really costs (`price_editable`).
 * Adds "Hala Bahrain" at 33 BHD that way, at the owner's request
 * (2026-11-16). Inserted only where no extra of that name exists yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('limo_extras')) {
            return;
        }

        if (! Schema::hasColumn('limo_extras', 'price_editable')) {
            Schema::table('limo_extras', function (Blueprint $table): void {
                $table->boolean('price_editable')->default(false);
            });
        }

        if (DB::table('limo_extras')->where('name', 'like', '%Hala Bahrain%')->exists()) {
            return;
        }

        $now = now();
        DB::table('limo_extras')->insert([
            'name' => json_encode(['en' => 'Hala Bahrain', 'ar' => 'هلا بحرين'], JSON_UNESCAPED_UNICODE),
            'price' => 33,
            'price_editable' => true,
            'active' => true,
            'sequence' => ((int) DB::table('limo_extras')->max('sequence')) + 10,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('limo_extras', 'price_editable')) {
            Schema::table('limo_extras', function (Blueprint $table): void {
                $table->dropColumn('price_editable');
            });
        }
    }
};
