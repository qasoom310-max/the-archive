<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the automatic driver-details messages have done for each trip
 * ({@see \Modules\Limousine\Services\CrewNotices}):
 *
 * - `crew_notified_at` / `crew_notified_for` — when the customer was sent the
 *   driver and car, and for which pair ("car_id:driver_id"), so a later change
 *   of car or driver sends the customer the new details, and nothing else does.
 * - `crew_reminded_at` — the last reminder to operations (repeated every few
 *   minutes until a car and driver are put on the trip).
 * - `crew_escalated_at` — when the owner was told the 12-hour promise was
 *   missed (once per trip).
 *
 * All nullable and empty to start with: no trip has been sent anything yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('limo_legs', function (Blueprint $table): void {
            if (! Schema::hasColumn('limo_legs', 'crew_notified_at')) {
                $table->timestamp('crew_notified_at')->nullable();
            }
            if (! Schema::hasColumn('limo_legs', 'crew_notified_for')) {
                $table->string('crew_notified_for', 40)->nullable();
            }
            if (! Schema::hasColumn('limo_legs', 'crew_reminded_at')) {
                $table->timestamp('crew_reminded_at')->nullable();
            }
            if (! Schema::hasColumn('limo_legs', 'crew_escalated_at')) {
                $table->timestamp('crew_escalated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('limo_legs', function (Blueprint $table): void {
            foreach (['crew_notified_at', 'crew_notified_for', 'crew_reminded_at', 'crew_escalated_at'] as $column) {
                if (Schema::hasColumn('limo_legs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
