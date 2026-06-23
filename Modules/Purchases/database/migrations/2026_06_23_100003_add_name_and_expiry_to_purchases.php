<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A vendor bill gains an optional human name/label and an optional expiry date
 * (e.g. the shelf-life of perishable goods on the bill). Both descriptive — no
 * effect on stock/accounting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            $table->string('name')->nullable()->after('reference');
            $table->date('expiry_date')->nullable()->after('date');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropColumn(['name', 'expiry_date']);
        });
    }
};
