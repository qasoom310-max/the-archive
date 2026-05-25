<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();

            // `name` is translatable (Spatie HasTranslations) — stored as a
            // JSON envelope `{"en": "...", "ar": "..."}`. TEXT (not VARCHAR)
            // because the envelope can grow past 255 chars once a third
            // locale lands. SQLite ignores the type but the column behaves
            // identically; MySQL/Postgres need TEXT for the JSON cast.
            $table->text('name');

            // asset | liability | equity | income | expense — enforced in
            // the Eloquent cast via `AccountType`; stored as plain string
            // for driver-portability (SQLite has no native enum).
            $table->string('type', 20);

            // Self-referential hierarchy. We FK within the same module —
            // restrict on delete so a parent with children can't disappear.
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('accounts')
                ->restrictOnDelete();

            // Whether journal items on this account can be reconciled
            // (bank account, AR, AP — yes; sales income — no). UI only;
            // no engine enforcement yet.
            $table->boolean('is_reconcilable')->default(false);

            // Soft "hide from picker" without deleting — once an account
            // has journal items it can't be safely removed.
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->index('type');
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
