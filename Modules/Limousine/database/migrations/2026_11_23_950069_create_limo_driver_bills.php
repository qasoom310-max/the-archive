<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bills a driver photographed himself, through the link sent with his trip.
 * They wait here until the office checks each one and saves it as an expense
 * (or sets it aside). Nothing is deleted: a set-aside bill keeps its photo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('limo_driver_bills')) {
            return;
        }

        Schema::create('limo_driver_bills', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('driver_id')->index();
            $table->unsignedBigInteger('leg_id')->nullable()->index();
            $table->string('photo_path');
            $table->json('draft')->nullable();
            // new | saved | discarded
            $table->string('status', 16)->default('new')->index();
            $table->unsignedBigInteger('expense_id')->nullable();
            $table->unsignedBigInteger('handled_by_user_id')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limo_driver_bills');
    }
};
