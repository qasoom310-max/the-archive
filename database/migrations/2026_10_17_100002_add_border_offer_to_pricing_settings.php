<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cross-border empty-car offer on the website: a car that has to drive
 * between Bahrain and Saudi Arabia empty anyway is offered at a discount.
 * Its rules live with the other website settings (Fares → Settings).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pricing_settings') || Schema::hasColumn('pricing_settings', 'border_enabled')) {
            return;
        }

        Schema::table('pricing_settings', function (Blueprint $table): void {
            $table->boolean('border_enabled')->default(true);
            $table->decimal('border_discount_percent', 5, 2)->default(30);
            // Door to door between the two countries, causeway included.
            $table->decimal('border_travel_hours', 4, 1)->default(2);
            // How long a car may stand in the other country for a passenger.
            $table->unsignedSmallInteger('border_wait_hours')->default(4);
            // Least notice for a discounted ride (shorter than a normal booking:
            // these cars are on the road anyway).
            $table->unsignedSmallInteger('border_notice_hours')->default(2);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pricing_settings', 'border_enabled')) {
            return;
        }

        Schema::table('pricing_settings', function (Blueprint $table): void {
            $table->dropColumn(['border_enabled', 'border_discount_percent', 'border_travel_hours', 'border_wait_hours', 'border_notice_hours']);
        });
    }
};
