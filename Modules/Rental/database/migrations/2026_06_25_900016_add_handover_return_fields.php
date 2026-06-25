<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Condition capture when the car is handed over and when it comes back — the
 * details the desk records on the spot: fuel level, KM (so the fleet odometer
 * stays current for maintenance), any pre-existing problem, and on return
 * whether the customer caused damage. Videos are not stored here; a cloud
 * share link is kept instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            // Handover (start).
            $table->unsignedInteger('handover_km')->nullable()->after('payment_status');
            $table->string('handover_fuel')->nullable()->after('handover_km');
            $table->text('handover_notes')->nullable()->after('handover_fuel');
            $table->string('handover_video_url')->nullable()->after('handover_notes');
            $table->dateTime('started_at')->nullable()->after('handover_video_url');

            // Return (close).
            $table->unsignedInteger('return_km')->nullable()->after('started_at');
            $table->string('return_fuel')->nullable()->after('return_km');
            $table->boolean('has_damage')->default(false)->after('return_fuel');
            $table->text('damage_notes')->nullable()->after('has_damage');
            $table->string('damage_video_url')->nullable()->after('damage_notes');
            $table->dateTime('returned_at')->nullable()->after('damage_video_url');
        });
    }

    public function down(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'handover_km', 'handover_fuel', 'handover_notes', 'handover_video_url', 'started_at',
                'return_km', 'return_fuel', 'has_damage', 'damage_notes', 'damage_video_url', 'returned_at',
            ]);
        });
    }
};
