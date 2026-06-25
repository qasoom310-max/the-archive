<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security-deposit settlement. The deposit is held (typically 14 days) after the
 * car is returned; an accountant / super-admin then refunds it, deducts part of
 * it, or keeps it all — with a reason and photos for any deduction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->string('deposit_status')->default('held')->after('deposit');
            $table->decimal('deposit_deducted', 10, 3)->default(0)->after('deposit_status');
            $table->text('deposit_reason')->nullable()->after('deposit_deducted');
            $table->json('deposit_images')->nullable()->after('deposit_reason');
            $table->unsignedBigInteger('deposit_resolved_by_user_id')->nullable()->after('deposit_images');
            $table->dateTime('deposit_resolved_at')->nullable()->after('deposit_resolved_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('rental_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'deposit_status', 'deposit_deducted', 'deposit_reason',
                'deposit_images', 'deposit_resolved_by_user_id', 'deposit_resolved_at',
            ]);
        });
    }
};
