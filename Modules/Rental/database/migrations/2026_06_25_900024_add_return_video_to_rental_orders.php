<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A mandatory return video, captured every time the car comes back (distinct
 * from the optional damage video, which only applies when the customer caused
 * damage). Stored as a Cloudflare Stream public share link, like the others.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->string('return_video_url')->nullable()->after('returned_at');
        });
    }

    public function down(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->dropColumn('return_video_url');
        });
    }
};
