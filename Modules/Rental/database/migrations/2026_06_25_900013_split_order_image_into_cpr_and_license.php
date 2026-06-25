<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single handover image on a rental order becomes two named document
 * uploads: the customer's CPR / ID and their driving licence — what a rental
 * desk actually files against a contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->string('cpr_image_path')->nullable()->after('notes');
            $table->string('license_image_path')->nullable()->after('cpr_image_path');
        });

        if (Schema::hasColumn('rental_orders', 'image_path')) {
            Schema::table('rental_orders', function (Blueprint $table): void {
                $table->dropColumn('image_path');
            });
        }
    }

    public function down(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('notes');
            $table->dropColumn(['cpr_image_path', 'license_image_path']);
        });
    }
};
