<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-(prefix, year) running counter for human-readable journal
     * numbers. SequenceGenerator picks `last_number + 1` under a row
     * lock — atomic across concurrent inserts on MySQL/Postgres, and
     * still race-safe on SQLite (single-writer).
     */
    public function up(): void
    {
        Schema::create('accounting_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('prefix', 16);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['prefix', 'year'], 'acc_seq_prefix_year_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_sequences');
    }
};
