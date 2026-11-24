<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who ended a trip from the office (the driver forgot), and why. Null = the driver ended it. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('limo_trip_runs') || Schema::hasColumn('limo_trip_runs', 'ended_by')) {
            return;
        }

        Schema::table('limo_trip_runs', function (Blueprint $table): void {
            $table->string('ended_by', 120)->nullable()->after('ended_at');
            $table->string('ended_note', 255)->nullable()->after('ended_by');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('limo_trip_runs', 'ended_by')) {
            Schema::table('limo_trip_runs', function (Blueprint $table): void {
                $table->dropColumn(['ended_by', 'ended_note']);
            });
        }
    }
};
