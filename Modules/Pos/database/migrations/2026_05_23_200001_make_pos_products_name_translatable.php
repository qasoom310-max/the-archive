<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make `pos_products.name` translatable via spatie/laravel-translatable.
 *
 * Storage convention: the column holds a JSON object keyed by locale, e.g.
 * `{"en": "Espresso", "ar": "إسبريسو"}`. Spatie's `HasTranslations` trait
 * encodes/decodes transparently at the model layer; reads via `$p->name`
 * return the active-locale value (driven by `app()->getLocale()`, which
 * `SetLocale` middleware sets from `company.language`).
 *
 * Two parts:
 *  1. Column widened from VARCHAR to TEXT so the JSON payload (which grows
 *     beyond ~50 chars even for short names once wrapped in `{"en":""}`)
 *     can never overflow on MySQL. SQLite has dynamic typing so this is a
 *     no-op there; doing it unconditionally keeps the schema in sync.
 *  2. Data migration: existing plain-string values (`"Espresso"`) get
 *     wrapped to `{"en": "Espresso"}`. Idempotent — rows whose `name`
 *     already starts with `{` are skipped, so a partial migration can be
 *     safely re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->text('name')->change();
        });

        // Wrap existing plain-string names as `{"en": value}` so Spatie's
        // JSON decoder finds the English translation. `LIKE '{%'` shields
        // already-encoded rows (idempotent re-run on a partial migration,
        // and harmless if a future migration ever leaves things half-done).
        DB::table('pos_products')
            ->where('name', 'not like', '{%')
            ->orderBy('id')
            ->each(function (object $row): void {
                /** @var object{id: int, name: string} $row */
                DB::table('pos_products')
                    ->where('id', $row->id)
                    ->update(['name' => json_encode(['en' => $row->name], JSON_UNESCAPED_UNICODE)]);
            });
    }

    public function down(): void
    {
        // Reverse the data migration first: unwrap the JSON back to its
        // English value (or empty string if `en` isn't present). We do
        // this BEFORE altering the column type so values fitting the new
        // VARCHAR(255) limit aren't truncated.
        DB::table('pos_products')
            ->where('name', 'like', '{%')
            ->orderBy('id')
            ->each(function (object $row): void {
                /** @var object{id: int, name: string} $row */
                $decoded = json_decode($row->name, true);
                $value = is_array($decoded) && isset($decoded['en']) && is_string($decoded['en'])
                    ? $decoded['en']
                    : '';

                DB::table('pos_products')
                    ->where('id', $row->id)
                    ->update(['name' => $value]);
            });

        Schema::table('pos_products', function (Blueprint $table): void {
            $table->string('name')->change();
        });
    }
};
