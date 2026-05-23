<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user per-model column visibility + ordering for engine ListViews.
 *
 * One row per (user, model_key). `hidden_columns` is the list of arch
 * column `field` names the user has hidden; `column_order` is the
 * preferred display order — empty means "use the arch's natural order".
 * Unknown / removed columns are filtered at read time, so an arch
 * change can never crash a view from a stale preference row.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('user_view_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('model_key', 64);
            $table->json('hidden_columns')->nullable();
            $table->json('column_order')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'model_key'], 'uvp_user_model_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_view_preferences');
    }
};
