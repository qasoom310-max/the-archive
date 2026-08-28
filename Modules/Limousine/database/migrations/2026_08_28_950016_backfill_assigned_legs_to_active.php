<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Start trips that were already crewed before the rule existed.
 *
 * Assigning a driver now moves a leg to Active, but that runs at the moment of
 * assignment — so every leg crewed BEFORE it shipped was left sitting in the
 * queue with a car and a driver against it, looking un-dispatched. This puts
 * them where they belong instead of making the office re-assign each one.
 *
 * Only legs with BOTH a car and a driver, and only from queue/confirmed: a
 * completed or cancelled trip is never dragged back onto the road, and a leg
 * missing either half is genuinely not dispatched yet.
 *
 * The parent bookings are then re-derived from their legs — a booking reads as
 * its LEAST-progressed live leg, so a job with one leg now running and another
 * still waiting stays in the queue rather than claiming to be active.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('limo_legs') || ! Schema::hasTable('limo_bookings')) {
            return;
        }

        // `legable` is a morph, so this must not touch quotation legs — they
        // are not dispatched and have no status at all.
        $bookingLegs = DB::table('limo_legs')
            ->whereNotNull('driver_id')
            ->whereNotNull('car_id')
            ->whereIn('status', ['queue', 'confirmed'])
            ->whereIn('legable_id', fn ($q) => $q->select('id')->from('limo_bookings'))
            ->where('legable_type', 'like', '%LimoBooking')
            ->pluck('legable_id', 'id');

        if ($bookingLegs->isEmpty()) {
            return;
        }

        DB::table('limo_legs')->whereIn('id', $bookingLegs->keys())->update(['status' => 'active']);

        foreach (array_unique($bookingLegs->values()->all()) as $bookingId) {
            $this->syncBooking((int) $bookingId);
        }
    }

    /**
     * Re-derive one booking's status from its legs: the least-progressed live
     * one wins, cancelled legs are ignored unless every leg is cancelled.
     *
     * Deliberately duplicated from LimoBooking::syncStatusFromLegs() rather
     * than called — a migration must keep behaving the way it did the day it
     * ran, even after the model moves on.
     */
    private function syncBooking(int $bookingId): void
    {
        $statuses = DB::table('limo_legs')
            ->where('legable_id', $bookingId)
            ->where('legable_type', 'like', '%LimoBooking')
            ->whereNotNull('status')
            ->pluck('status')
            ->all();

        if ($statuses === []) {
            return;
        }

        $live = array_values(array_filter($statuses, static fn (string $s): bool => $s !== 'cancelled'));
        if ($live === []) {
            DB::table('limo_bookings')->where('id', $bookingId)->update(['status' => 'cancelled']);

            return;
        }

        foreach (['queue', 'confirmed', 'active'] as $candidate) {
            if (in_array($candidate, $live, true)) {
                DB::table('limo_bookings')->where('id', $bookingId)->update(['status' => $candidate]);

                return;
            }
        }

        DB::table('limo_bookings')->where('id', $bookingId)->update(['status' => 'completed']);
    }

    public function down(): void
    {
        // Deliberately irreversible: nothing records which legs this moved, and
        // guessing would push genuinely running trips back into the queue.
    }
};
