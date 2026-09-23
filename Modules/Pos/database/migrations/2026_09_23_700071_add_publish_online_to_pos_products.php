<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a product may appear in the online store.
 *
 * A product can be perfectly real in the shop — sold at the register, counted
 * in stock — while it is not ready to be seen by a customer online, because it
 * still has no photo or description. `active` cannot say that: turning it off
 * takes the product off the register too.
 *
 * Defaults to true, so every product already on file keeps behaving exactly as
 * it does today, and the switch is something the shop turns OFF deliberately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->boolean('publish_online')->default(true)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('pos_products', function (Blueprint $table): void {
            $table->dropColumn('publish_online');
        });
    }
};
