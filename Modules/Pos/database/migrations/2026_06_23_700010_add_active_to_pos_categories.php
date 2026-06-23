<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A POS category can be deactivated — an inactive category is hidden from the
 * register (its chip and products' chip no longer show). Defaults active so
 * every existing category stays visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_categories', function (Blueprint $table): void {
            $table->boolean('active')->default(true)->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('pos_categories', function (Blueprint $table): void {
            $table->dropColumn('active');
        });
    }
};
