<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a category as "drinks". Used by the Sweileh Café happy hour to EXCLUDE
 * drinks from the food discount — food gets the % off, drinks do not. Default
 * false, so nothing is treated as a drink until an admin ticks it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_categories', function (Blueprint $table): void {
            $table->boolean('is_drink')->default(false)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('pos_categories', function (Blueprint $table): void {
            $table->dropColumn('is_drink');
        });
    }
};
