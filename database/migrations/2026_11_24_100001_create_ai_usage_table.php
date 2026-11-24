<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each call to Claude cost: which feature made it (Abdullah, the bill
 * reader, the dashboard reader, the WhatsApp assistant), the model that
 * answered, its tokens and the price worked out from them. Shown on
 * Settings → Abdullah as the month's spending. Calls before this table have
 * no cost on record.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_usage')) {
            return;
        }

        Schema::create('ai_usage', function (Blueprint $table): void {
            $table->id();
            $table->string('feature', 32)->index();
            $table->string('model', 64);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
