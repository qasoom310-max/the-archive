<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turn maintenance into a proper work-order workflow (industry practice, e.g.
 * Fleetio / Oxmaint): a request is raised, a manager approves or declines it
 * before any work / spend happens, then it's started and completed. Adds the
 * priority, the approver + decision time, and start/complete timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_maintenance', function (Blueprint $table): void {
            $table->string('priority')->default('normal')->after('type');
            $table->unsignedBigInteger('approved_by_user_id')->nullable()->after('status');
            $table->dateTime('approved_at')->nullable()->after('approved_by_user_id');
            $table->dateTime('started_at')->nullable()->after('approved_at');
            $table->dateTime('completed_at')->nullable()->after('started_at');
        });

        // Legacy records used "scheduled" as the initial state; the new initial
        // state is "pending" (awaiting approval). Idempotent.
        DB::table('rental_maintenance')->where('status', 'scheduled')->update(['status' => 'pending']);
    }

    public function down(): void
    {
        Schema::table('rental_maintenance', function (Blueprint $table): void {
            $table->dropColumn(['priority', 'approved_by_user_id', 'approved_at', 'started_at', 'completed_at']);
        });
    }
};
