<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make `pos_floors.name` translatable via spatie/laravel-translatable.
 * Mirrors the product/category translatable migrations — same {"en":…,"ar":…}
 * JSON-on-TEXT convention, same idempotent data wrap, reversible down().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_floors', function (Blueprint $table): void {
            $table->text('name')->change();
        });

        DB::table('pos_floors')
            ->where('name', 'not like', '{%')
            ->orderBy('id')
            ->each(function (object $row): void {
                /** @var object{id: int, name: string} $row */
                DB::table('pos_floors')
                    ->where('id', $row->id)
                    ->update(['name' => json_encode(['en' => $row->name], JSON_UNESCAPED_UNICODE)]);
            });
    }

    public function down(): void
    {
        DB::table('pos_floors')
            ->where('name', 'like', '{%')
            ->orderBy('id')
            ->each(function (object $row): void {
                /** @var object{id: int, name: string} $row */
                $decoded = json_decode($row->name, true);
                $value = is_array($decoded) && isset($decoded['en']) && is_string($decoded['en'])
                    ? $decoded['en']
                    : '';

                DB::table('pos_floors')
                    ->where('id', $row->id)
                    ->update(['name' => $value]);
            });

        Schema::table('pos_floors', function (Blueprint $table): void {
            $table->string('name')->change();
        });
    }
};
