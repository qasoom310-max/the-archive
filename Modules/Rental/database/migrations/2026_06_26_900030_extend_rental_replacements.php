<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turn a replacement into a proper swap event on a live rental: classify the
 * reason, capture the original car coming in and the replacement going out
 * (KM / fuel / condition), and record who performed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_replacements', function (Blueprint $table): void {
            $table->string('reason_type')->nullable()->after('reason');
            $table->unsignedInteger('original_return_km')->nullable()->after('reason_type');
            $table->string('original_return_fuel')->nullable()->after('original_return_km');
            $table->text('original_condition_notes')->nullable()->after('original_return_fuel');
            $table->unsignedInteger('replacement_handover_km')->nullable()->after('original_condition_notes');
            $table->string('replacement_handover_fuel')->nullable()->after('replacement_handover_km');
            $table->text('replacement_condition_notes')->nullable()->after('replacement_handover_fuel');
            $table->unsignedBigInteger('created_by_user_id')->nullable()->after('replacement_condition_notes');
        });
    }

    public function down(): void
    {
        Schema::table('rental_replacements', function (Blueprint $table): void {
            $table->dropColumn([
                'reason_type', 'original_return_km', 'original_return_fuel', 'original_condition_notes',
                'replacement_handover_km', 'replacement_handover_fuel', 'replacement_condition_notes',
                'created_by_user_id',
            ]);
        });
    }
};
