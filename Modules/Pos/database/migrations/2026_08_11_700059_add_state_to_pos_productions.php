<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give a production run a lifecycle: done (recorded, stock applied), draft
 * (reopened for editing, stock NOT applied), reversed (undone + locked for the
 * record). Existing rows are recorded runs, so they backfill to 'done'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_productions', function (Blueprint $table): void {
            $table->string('state', 12)->default('done')->after('produced_units')->index();
            $table->timestamp('reversed_at')->nullable()->after('state');
            $table->unsignedBigInteger('reversed_by_user_id')->nullable()->after('reversed_at');
        });
    }

    public function down(): void
    {
        Schema::table('pos_productions', function (Blueprint $table): void {
            $table->dropColumn(['state', 'reversed_at', 'reversed_by_user_id']);
        });
    }
};
