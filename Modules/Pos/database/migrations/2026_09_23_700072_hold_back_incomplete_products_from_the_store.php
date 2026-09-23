<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Switch the online-store flag off for products that are not ready to be seen:
 * no category, no price, or no photo.
 *
 * The column defaults to true, which is right for a catalogue that is already
 * on a store, but a shop turning the feature on today has products still
 * waiting for their photos — and those should start held back rather than
 * have to be found by hand. From here the model's own saving hook keeps the
 * flag honest.
 *
 * Data only: nothing is pushed to any store by this, so a listing already up
 * there comes down on the next sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pos_products')
            ->where('publish_online', true)
            ->where(function ($query): void {
                $query->whereNull('pos_category_id')
                    ->orWhereNull('image_path')
                    ->orWhere('image_path', '')
                    ->orWhereNull('price')
                    ->orWhere('price', '<=', 0);
            })
            ->update(['publish_online' => false]);
    }

    public function down(): void
    {
        // Which products were switched off here is not recorded, and turning
        // them all back on would put unfinished listings on the store.
    }
};
