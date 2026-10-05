<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Backup\DatabaseBackup;
use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCoupon;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoQuotation;
use Tests\TestCase;

/**
 * Trip numbers follow on from the highest one on file. They used to come from
 * the row id, and imports had pushed the id counter far ahead, so new trips
 * were numbered 41,7xx while the old ones stopped at 26,2xx.
 */
final class LimoTripNumberSequenceTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function booking(bool $imported = false): LimoBooking
    {
        $booking = LimoBooking::query()->create([
            'customer_id' => LimoCustomer::query()->create(['name' => 'C'])->id,
            'status' => LimoBooking::STATUS_QUEUE,
        ]);
        if ($imported) {
            $booking->forceFill(['imported_at' => now()])->saveQuietly();
        }

        return $booking;
    }

    private function leg(LimoBooking|LimoQuotation $parent, ?string $reference = null): LimoLeg
    {
        $leg = new LimoLeg(['sequence' => 1, 'from_location' => 'A', 'to_location' => 'B', 'rate' => 10, 'net_amount' => 10]);
        if ($reference !== null) {
            $leg->reference = $reference;
        }
        $parent->legs()->save($leg);

        return $leg->refresh();
    }

    public function test_a_new_trip_follows_the_highest_number_not_the_row_id(): void
    {
        $this->leg($this->booking(true), '26214');

        // The id counter is far ahead of the trip numbers, as after a re-import.
        LimoLeg::query()->create([
            'legable_type' => (new LimoBooking())->getMorphClass(), 'legable_id' => $this->booking()->id,
            'reference' => '10050', 'sequence' => 1,
        ])->forceFill(['id' => 31730])->saveQuietly();

        $this->assertSame('26215', $this->leg($this->booking())->reference);
        $this->assertSame('26216', $this->leg($this->booking())->reference);
    }

    /**
     * What Wanaan actually looks like: the re-import filled every five-digit
     * number up to ~41,7xx with old trips, so "after the old ones" had no
     * room and pushed the live trips higher. Live trips move to 200001+.
     */
    public function test_live_trips_are_numbered_from_200001_when_old_trips_fill_the_five_digit_range(): void
    {
        $this->leg($this->booking(true), '26214');
        $oldTop = $this->leg($this->booking(true), '41747');
        // Where the previous fix left the live ones: pushed above the old top.
        $liveA = $this->leg($this->booking(), '41786');
        $liveB = $this->leg($this->booking(), '41800');
        $quoteLeg = $this->leg(LimoQuotation::query()->create(['customer_id' => LimoCustomer::query()->create(['name' => 'Q'])->id]), '41801');
        LimoCoupon::query()->create(['code' => 'CPN-LIVE', 'leg_reference' => '41800', 'amount' => 5]);

        $migration = require base_path('Modules/Limousine/database/migrations/2026_09_30_950038_number_live_trips_from_200001.php');
        $migration->up();

        $this->assertSame('41747', $oldTop->refresh()->reference);
        $this->assertSame('200001', $liveA->refresh()->reference);
        $this->assertSame('200002', $liveB->refresh()->reference);
        // Quotation trips are left as they are.
        $this->assertSame('41801', $quoteLeg->refresh()->reference);
        $this->assertSame('200002', LimoCoupon::query()->where('code', 'CPN-LIVE')->value('leg_reference'));

        // New trips carry on from there — six digits starting with 2.
        $this->assertSame('200003', $this->leg($this->booking())->reference);

        // Running again changes nothing.
        $migration->up();
        $this->assertSame('200001', $liveA->refresh()->reference);
    }

    /**
     * After the 22 Sep wipe the re-imported trips sat at 26215–41697 with
     * 10000–26214 empty, so the oldest trips carried 4xxxx numbers. Closing
     * the gap leaves no number starting with 3 or 4.
     */
    public function test_old_trip_numbers_close_up_so_none_start_with_four(): void
    {
        $first = $this->leg($this->booking(true), '26215');
        $middle = $this->leg($this->booking(true), '35000');
        $oldest = $this->leg($this->booking(true), '41697');
        $quoteLeg = $this->leg(LimoQuotation::query()->create(['customer_id' => LimoCustomer::query()->create(['name' => 'Q'])->id]), '41801');
        $live = $this->leg($this->booking(), '200001');
        LimoCoupon::query()->create(['code' => 'CPN-OLD', 'leg_reference' => '41697', 'amount' => 5]);
        LimoCoupon::query()->create(['code' => 'CPN-LIVE', 'leg_reference' => '200001', 'amount' => 5]);

        $migration = require base_path('Modules/Limousine/database/migrations/2026_10_05_950039_close_up_old_trip_numbers_from_10000.php');
        $migration->up();

        $this->assertSame('10000', $first->refresh()->reference);
        $this->assertSame('10001', $middle->refresh()->reference);
        $this->assertSame('10002', $oldest->refresh()->reference);
        $this->assertSame('10003', $quoteLeg->refresh()->reference);
        $this->assertSame('200001', $live->refresh()->reference);
        $this->assertSame('41697', $oldest->previous_reference);
        $this->assertSame('10002', LimoCoupon::query()->where('code', 'CPN-OLD')->value('leg_reference'));
        $this->assertSame('200001', LimoCoupon::query()->where('code', 'CPN-LIVE')->value('leg_reference'));
        $this->assertNotEmpty(app(DatabaseBackup::class)->list());

        // New trips still carry on from the live numbers.
        $this->assertSame('200002', $this->leg($this->booking())->reference);

        // Running again changes nothing.
        $migration->up();
        $this->assertSame('10002', $oldest->refresh()->reference);
    }

    public function test_closing_up_keeps_the_order_and_shifts_a_full_block_by_the_same_amount(): void
    {
        $legs = [];
        foreach (['26215', '26216', '39999', '40000', '41697'] as $ref) {
            $legs[$ref] = $this->leg($this->booking(true), $ref);
        }
        $this->leg($this->booking(), '200001');

        $migration = require base_path('Modules/Limousine/database/migrations/2026_10_05_950039_close_up_old_trip_numbers_from_10000.php');
        $migration->up();

        $this->assertSame(['10000', '10001', '10002', '10003', '10004'], array_map(
            static fn (LimoLeg $leg): string => (string) $leg->refresh()->reference,
            array_values($legs),
        ));
    }

    /** A coupon whose trip was deleted must not end up naming the trip that now has its number. */
    public function test_a_coupon_for_a_deleted_trip_is_marked_old(): void
    {
        $this->leg($this->booking(true), '26215');
        $this->leg($this->booking(), '200001');
        LimoCoupon::query()->create(['code' => 'CPN-GONE', 'limo_leg_id' => 999999, 'leg_reference' => '10000', 'amount' => 5]);

        $migration = require base_path('Modules/Limousine/database/migrations/2026_10_05_950039_close_up_old_trip_numbers_from_10000.php');
        $migration->up();

        $this->assertSame('old-10000', LimoCoupon::query()->where('code', 'CPN-GONE')->value('leg_reference'));
    }

    /** Without six-digit live trips, the freed numbers would be handed out again. */
    public function test_closing_up_waits_until_live_trips_run_on_six_digits(): void
    {
        $leg = $this->leg($this->booking(true), '41697');

        $migration = require base_path('Modules/Limousine/database/migrations/2026_10_05_950039_close_up_old_trip_numbers_from_10000.php');
        $migration->up();

        $this->assertSame('41697', $leg->refresh()->reference);
    }

    /** An export taken before the renumbering still carries the old number. */
    public function test_reimporting_an_export_with_the_old_number_is_recognised(): void
    {
        $leg = $this->leg($this->booking(true), '41697');
        $this->leg($this->booking(), '200001');

        $migration = require base_path('Modules/Limousine/database/migrations/2026_10_05_950039_close_up_old_trip_numbers_from_10000.php');
        $migration->up();

        $path = tempnam(sys_get_temp_dir(), 'bkg') . '.csv';
        file_put_contents($path, "Reference,From date,To date,Type,Customer,Amount,Received,Pickup,Drop off,Vehicle,Driver,Company reference,Pax name,Status,Payment\n"
            . "41697,2022-01-01 09:00,,Transfer,C,10,10,A,B,,,,,Completed,Paid\n");

        $result = app(\Modules\Limousine\Support\BookingImporter::class)->import($path);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, LimoLeg::query()->where('previous_reference', '41697')->count());
        $this->assertSame('10000', $leg->refresh()->reference);
    }

    public function test_closing_up_skips_a_database_without_the_legacy_import(): void
    {
        $leg = $this->leg($this->booking(), '41697');

        $migration = require base_path('Modules/Limousine/database/migrations/2026_10_05_950039_close_up_old_trip_numbers_from_10000.php');
        $migration->up();

        $this->assertSame('41697', $leg->refresh()->reference);
    }

    public function test_the_reference_column_sorts_live_six_digit_trips_above_old_ones(): void
    {
        $old = $this->leg($this->booking(true), '41697');
        $live = $this->leg($this->booking(), '200001');

        $ids = (new \Modules\Limousine\Services\LimoQueueRows())
            ->query(\Modules\Limousine\Services\LimoQueueRows::TAB_ALL, '', '', '', 'reference', 'desc')
            ->pluck('id')
            ->all();

        $this->assertSame([$live->id, $old->id], array_values(array_filter($ids, static fn (mixed $id): bool => in_array($id, [$live->id, $old->id], true))));
    }

    public function test_a_database_without_the_legacy_import_keeps_its_own_numbers(): void
    {
        $leg = $this->leg($this->booking(), '10005');

        $migration = require base_path('Modules/Limousine/database/migrations/2026_09_30_950038_number_live_trips_from_200001.php');
        $migration->up();

        $this->assertSame('10005', $leg->refresh()->reference);
        $this->assertSame('10006', $this->leg($this->booking())->reference);
    }

    public function test_a_fresh_database_still_starts_at_ten_thousand(): void
    {
        $this->assertSame((string) LimoLeg::REFERENCE_START, $this->leg($this->booking())->reference);
    }

    public function test_the_4xxxx_trips_move_to_follow_the_old_sequence(): void
    {
        $old = $this->leg($this->booking(true), '26214');
        $importedHigh = $this->leg($this->booking(true), '35000'); // old data is never touched
        $first = $this->leg($this->booking(), '41730');
        $second = $this->leg($this->booking(), '41731');
        $quoteLeg = $this->leg(LimoQuotation::query()->create(['customer_id' => LimoCustomer::query()->create(['name' => 'Q'])->id]), '41732');
        LimoCoupon::query()->create(['code' => 'CPN-TEST', 'leg_reference' => '41730', 'amount' => 5]);

        $migration = require base_path('Modules/Limousine/database/migrations/2026_09_29_950037_renumber_new_trips_after_the_old_sequence.php');
        $migration->up();

        $this->assertSame('26214', $old->refresh()->reference);
        $this->assertSame('35000', $importedHigh->refresh()->reference);
        $this->assertSame('26215', $first->refresh()->reference);
        $this->assertSame('26216', $second->refresh()->reference);
        $this->assertSame('26217', $quoteLeg->refresh()->reference);
        $this->assertSame('26215', LimoCoupon::query()->where('code', 'CPN-TEST')->value('leg_reference'));

        // A backup came first, and the next new trip carries on from there.
        $this->assertNotEmpty(app(DatabaseBackup::class)->list());
        // (35000 is an imported trip, so the next number is past it.)
        $this->assertSame('35001', $this->leg($this->booking())->reference);

        // Running again changes nothing.
        $migration->up();
        $this->assertSame('26215', $first->refresh()->reference);
    }
}
