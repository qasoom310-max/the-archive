<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money paid to an outside (commission) driver: when, how much, who asked for
 * it and who recorded it. A payment made by mistake is cancelled with a
 * reason, never deleted, so the record of money handed over stays complete.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('limo_driver_payments')) {
            return;
        }

        Schema::create('limo_driver_payments', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->nullable()->index();
            $table->unsignedBigInteger('driver_id')->index();
            $table->decimal('amount', 12, 3);
            $table->date('paid_on');
            $table->string('method', 16)->default('cash');
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limo_driver_payments');
    }
};
