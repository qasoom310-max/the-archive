<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Car papers: registration card and insurance, each with an expiry date and a
 * stored PDF. A car whose registration or insurance is missing/expired is held
 * back from the bookable list until it's renewed (super-admin can override for
 * urgent cases), and a reminder surfaces cars expiring within 30 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_vehicles', function (Blueprint $table): void {
            $table->date('registration_expiry')->nullable()->after('next_maintenance_mileage');
            $table->string('registration_doc')->nullable()->after('registration_expiry');
            $table->date('insurance_expiry')->nullable()->after('registration_doc');
            $table->string('insurance_doc')->nullable()->after('insurance_expiry');
        });
    }

    public function down(): void
    {
        Schema::table('rental_vehicles', function (Blueprint $table): void {
            $table->dropColumn(['registration_expiry', 'registration_doc', 'insurance_expiry', 'insurance_doc']);
        });
    }
};
