<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Map links for a trip's pick-up and drop-off.
 *
 * A written address gets a driver to the right street; a dropped pin gets them
 * to the right door. The office already pastes a Google Maps link into WhatsApp
 * by hand, so the link belongs on the leg next to the address it points at —
 * per leg, because each trip starts and ends somewhere different.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->string('from_location_url')->nullable()->after('from_location');
            $table->string('to_location_url')->nullable()->after('to_location');
        });
    }

    public function down(): void
    {
        Schema::table('limo_legs', function (Blueprint $table): void {
            $table->dropColumn(['from_location_url', 'to_location_url']);
        });
    }
};
