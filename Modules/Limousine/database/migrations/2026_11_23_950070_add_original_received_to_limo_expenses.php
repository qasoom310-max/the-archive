<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who confirmed the ORIGINAL paper bill reached the office, and when. The owner
 * must show the originals to the VAT authority at the year end, so a bill a
 * driver only photographed is saved as an expense only once its paper is in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('limo_expenses') && ! Schema::hasColumn('limo_expenses', 'original_received_at')) {
            Schema::table('limo_expenses', function (Blueprint $table): void {
                $table->timestamp('original_received_at')->nullable();
                $table->string('original_received_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('limo_expenses') && Schema::hasColumn('limo_expenses', 'original_received_at')) {
            Schema::table('limo_expenses', function (Blueprint $table): void {
                $table->dropColumn(['original_received_at', 'original_received_by']);
            });
        }
    }
};
