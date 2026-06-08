<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_order_lines', function (Blueprint $table): void {
            // Snapshot of the condiments chosen for this line:
            // [{"id":1,"name":"Extra cheese","price":0.5}, ...]. A snapshot
            // (not a live FK) so a later price/name edit on the catalogue
            // can't rewrite history on a finalised order.
            $table->json('condiments')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_lines', function (Blueprint $table): void {
            $table->dropColumn('condiments');
        });
    }
};
