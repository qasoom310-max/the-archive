<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traffic-fines check during the deposit hold: fines from the rental period often
 * arrive after the car is back, so a staff member records any fine (or confirms
 * none) before the deposit is settled. The amount feeds the deduction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->dateTime('fines_checked_at')->nullable()->after('returned_at');
            $table->decimal('fines_amount', 12, 3)->default(0)->after('fines_checked_at');
            $table->string('fines_notes')->nullable()->after('fines_amount');
            $table->unsignedBigInteger('fines_checked_by_user_id')->nullable()->after('fines_notes');
        });
    }

    public function down(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->dropColumn(['fines_checked_at', 'fines_amount', 'fines_notes', 'fines_checked_by_user_id']);
        });
    }
};
