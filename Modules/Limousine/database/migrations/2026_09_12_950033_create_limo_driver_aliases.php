<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an old system's driver name actually means.
 *
 * Trips carried over from the previous system name their driver in TEXT, and
 * the text is that system's LOGIN — "kown", "smakhlooq", "qmakki". Some of
 * those logins are drivers, some are office staff the trip was parked on, and
 * a few ("via", "apiuser") are not people at all. Nothing in the record says
 * which, so the earnings league was ranking login names and a real driver's
 * job history could not find his own older trips.
 *
 * One row here per distinct name, saying either which driver in the register
 * it is, or that it is the office and no driver drove it. Trips themselves are
 * never rewritten: the original text stays exactly as it was imported, and
 * every screen reads it THROUGH this table. So a mapping typed wrong is
 * corrected by changing it here, and nothing about the history is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limo_driver_aliases', function (Blueprint $table): void {
            $table->id();

            // The name as it appears on trips, lowercased and space-collapsed.
            $table->string('alias')->unique();

            // The register driver it means. Deliberately a logical reference to
            // the SHARED `rental_drivers` table rather than a foreign key, the
            // same way `limo_legs.driver_id` and the petty-cash tables point at
            // it — Limousine must install without Rental's tables present.
            $table->unsignedBigInteger('driver_id')->nullable()->index();

            // Set when the name is the office, an owner's account or a system
            // login: real trips, but nobody to credit them to.
            $table->boolean('is_office')->default(false);

            // Who decided, so a surprising mapping can be asked about.
            $table->string('decided_by')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limo_driver_aliases');
    }
};
