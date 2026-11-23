<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why the accountant rejected a driver's bill, and when someone told him in
 * person. A rejected bill stays with the accountant under the driver's name
 * until she has told him (the owner's rule: the driver's link lasts only 48
 * hours, so he is told face to face, not through it). He brings it again and
 * it is entered and checked anew.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('limo_driver_bills') && ! Schema::hasColumn('limo_driver_bills', 'reject_reason')) {
            Schema::table('limo_driver_bills', function (Blueprint $table): void {
                $table->string('reject_reason', 500)->nullable();
                $table->timestamp('told_at')->nullable();
                $table->string('told_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('limo_driver_bills') && Schema::hasColumn('limo_driver_bills', 'reject_reason')) {
            Schema::table('limo_driver_bills', function (Blueprint $table): void {
                $table->dropColumn(['reject_reason', 'told_at', 'told_by']);
            });
        }
    }
};
