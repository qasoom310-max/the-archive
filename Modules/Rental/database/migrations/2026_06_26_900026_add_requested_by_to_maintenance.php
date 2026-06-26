<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track who raised a maintenance work order, so the record shows "Requested by
 * …" and the requester can be shown the manager's decision (approved/declined).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_maintenance', function (Blueprint $table): void {
            $table->unsignedBigInteger('requested_by_user_id')->nullable()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('rental_maintenance', function (Blueprint $table): void {
            $table->dropColumn('requested_by_user_id');
        });
    }
};
