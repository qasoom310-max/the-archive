<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tag a purchase with the POS session it was recorded in, so a shift's purchases
 * show up alongside its sales in reporting. Nullable logical ref (no FK) — a
 * purchase raised outside an open session simply carries null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            $table->unsignedBigInteger('pos_session_id')->nullable()->index()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropColumn('pos_session_id');
        });
    }
};
