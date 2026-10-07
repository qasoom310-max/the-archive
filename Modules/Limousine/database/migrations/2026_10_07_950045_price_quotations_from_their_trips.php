<?php

declare(strict_types=1);

use App\Erp\Activity\ActivityLogger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoQuotation;

/**
 * A quotation whose trips are priced but whose own total reads 0 shows as
 * "0.00 BD" when picked for an invoice. Its total is the sum of its trips
 * ({@see LimoQuotation::recalcTotal()}), so it is worked out again here.
 *
 * The old system's quotation register has no trips and no prices at all, so
 * those stay at 0 (and are no longer offered for billing). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('limo_quotations') || ! Schema::hasTable('limo_legs')) {
            return;
        }

        $type = (new LimoQuotation())->getMorphClass();
        $ids = DB::table('limo_quotations as q')
            ->where('q.fare', '<=', 0)
            ->whereExists(static fn ($legs) => $legs->selectRaw('1')->from('limo_legs as l')
                ->whereColumn('l.legable_id', 'q.id')
                ->where('l.legable_type', $type)
                ->where('l.net_amount', '>', 0))
            ->pluck('q.id');

        $fixed = [];
        foreach ($ids as $id) {
            $quote = LimoQuotation::query()->find($id);
            if ($quote === null) {
                continue;
            }
            $quote->recalcTotal();
            $quote->saveQuietly();
            $fixed[] = sprintf('%s: %.3f', (string) $quote->reference, (float) $quote->fare);
        }

        if ($fixed === []) {
            return;
        }

        Log::info('Limousine quotations priced from their trips', ['quotations' => $fixed]);
        app(ActivityLogger::class)->log('updated', 'Quotations', sprintf('%d quotation(s) priced from their trips: %s', count($fixed), implode('; ', $fixed)));
    }

    public function down(): void
    {
        // Data only.
    }
};
