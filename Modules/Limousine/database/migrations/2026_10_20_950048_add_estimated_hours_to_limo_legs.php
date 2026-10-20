<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roughly how long the journey itself takes, 1 to 12 hours — the same question
 * the website's fare finder asks as "time on the road".
 *
 * Deliberately NOT `hours`, which is what the trip is PRICED by on an hourly
 * or chauffeur job. This one never touches the money: it says when the car is
 * expected to arrive, so the desk can see what else that car can take.
 * Blank on every trip taken before today, and optional after it — the office
 * often does not know yet, and a guess entered as fact is worse than none.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('limo_legs', 'estimated_hours')) {
            return;
        }

        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->unsignedTinyInteger('estimated_hours')->nullable()->after('hours');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('limo_legs', 'estimated_hours')) {
            Schema::table('limo_legs', function (Blueprint $table): void {
                $table->dropColumn('estimated_hours');
            });
        }
    }
};
