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
