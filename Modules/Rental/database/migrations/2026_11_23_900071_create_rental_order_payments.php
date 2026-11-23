<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every change to a rental order's Advance, dated: the money taken (or a
 * correction, or coupon credit) and the day it happened. The Advance itself is
 * a running total with no date, so the Daily Summary could not place rental
 * money on a day. Old orders get no rows: their advances have no date to give.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_order_payments')) {
            return;
        }

        Schema::create('rental_order_payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->date('date')->index();
            $table->decimal('amount', 12, 3);
            $table->string('kind', 16)->default('payment');
            $table->string('method', 32)->nullable();
            $table->string('taken_by', 120)->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_order_payments');
    }
};
