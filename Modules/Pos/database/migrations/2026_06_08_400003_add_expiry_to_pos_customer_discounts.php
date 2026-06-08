<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_customer_discounts', function (Blueprint $table): void {
            // Rolling expiry: the discount stops applying once `now` passes
            // this timestamp. Set to (activation date + window) when the
            // discount is created/re-activated, and pushed forward to
            // (order date + window) on every paid order that uses it.
            $table->timestamp('expires_at')->nullable()->after('active');
        });

        // Backfill any rows that predate this column (e.g. the demo discount)
        // so they pick up the rolling window from their creation date rather
        // than living forever. Done in PHP for driver portability.
        $window = \Modules\Pos\Models\PosCustomerDiscount::WINDOW_DAYS;

        foreach (DB::table('pos_customer_discounts')->whereNull('expires_at')->get() as $row) {
            $base = isset($row->created_at)
                ? Carbon::parse((string) $row->created_at)
                : Carbon::now();

            DB::table('pos_customer_discounts')
                ->where('id', $row->id)
                ->update(['expires_at' => $base->addDays($window)]);
        }
    }

    public function down(): void
    {
        Schema::table('pos_customer_discounts', function (Blueprint $table): void {
            $table->dropColumn('expires_at');
        });
    }
};
