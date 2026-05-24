<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make `pos_categories.name` translatable via spatie/laravel-translatable.
 * Mirrors `2026_05_23_200001_make_pos_products_name_translatable` — same
 * storage convention ({"en":..., "ar":...} JSON on a TEXT column), same
 * idempotent data wrap, same reversible down().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_categories', function (Blueprint $table): void {
            $table->text('name')->change();
        });

        // Wrap plain-string names as `{"en": value}` so Spatie's JSON
        // decoder finds the English translation. `LIKE '{%'` skips
        // already-encoded rows → safe to re-run on a partial migration.
        DB::table('pos_categories')
            ->where('name', 'not like', '{%')
            ->orderBy('id')
            ->each(function (object $row): void {
                /** @var object{id: int, name: string} $row */
                DB::table('pos_categories')
                    ->where('id', $row->id)
                    ->update(['name' => json_encode(['en' => $row->name], JSON_UNESCAPED_UNICODE)]);
            });
    }

    public function down(): void
    {
        // Unwrap JSON → English value (or empty string) before narrowing
        // the column type, so values that fit the new VARCHAR(255) limit
        // aren't truncated.
        DB::table('pos_categories')
            ->where('name', 'like', '{%')
            ->orderBy('id')
            ->each(function (object $row): void {
                /** @var object{id: int, name: string} $row */
                $decoded = json_decode($row->name, true);
                $value = is_array($decoded) && isset($decoded['en']) && is_string($decoded['en'])
                    ? $decoded['en']
                    : '';

                DB::table('pos_categories')
                    ->where('id', $row->id)
                    ->update(['name' => $value]);
            });

        Schema::table('pos_categories', function (Blueprint $table): void {
            $table->string('name')->change();
        });
    }
};
