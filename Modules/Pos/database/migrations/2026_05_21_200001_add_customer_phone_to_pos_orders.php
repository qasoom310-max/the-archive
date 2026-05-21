<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the destination phone number per order so the auto-receipt
 * listener has a target after the order is closed (and so re-sends are
 * possible from order history). Stored as a normalized E.164-ish string
 * (international digits, no '+'), exactly what Meta's API expects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            // 24 chars is plenty (longest country code 3 + national digits).
            // Indexed for receipt lookups by phone (history / re-send UI).
            $table->string('customer_phone', 24)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropIndex(['customer_phone']);
            $table->dropColumn('customer_phone');
        });
    }
};
