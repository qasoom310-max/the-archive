<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-database settings for the automatic driver-details messages: the master
 * switch, the operations team's WhatsApp numbers (reminded until a trip has a
 * car and a driver) and the owner's number (told when the 12-hour promise to a
 * customer is missed). Single row, edited under Settings → Driver details.
 *
 * `enabled` defaults to false, so nothing is sent from any database until an
 * admin has entered the numbers and turned it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('limo_crew_alert_configuration')) {
            return;
        }

        Schema::create('limo_crew_alert_configuration', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->text('operations_numbers')->nullable();
            $table->string('owner_number', 40)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limo_crew_alert_configuration');
    }
};
