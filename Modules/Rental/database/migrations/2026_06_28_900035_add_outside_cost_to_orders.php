<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What we pay the outside vendor for THIS booking (cars rented in from outside are
 * costed per booking). Net revenue = order total − this cost; 0 for owned cars.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->decimal('outside_cost', 12, 3)->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->dropColumn('outside_cost');
        });
    }
};
