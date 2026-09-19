<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\LimoQueueRows;
use Tests\TestCase;

/**
 * The Vehicle column says what kind of car the trip needs.
 *
 * A particular car is assigned late — often the morning of the trip — but the
 * KIND of car is agreed when the booking is taken. The column only ever
 * printed the assigned car, so every unbooked-out row said nothing at all
 * about a fact the booking page had on it all along.
 */
final class LimoQueueVehicleTypeTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    /**
     * @param array<string, mixed> $legAttrs
     * @param array<string, mixed> $bookingAttrs
     */
    private function leg(array $legAttrs = [], array $bookingAttrs = []): LimoLeg
    {
        $customer = LimoCustomer::query()->create(['name' => 'Braxtone Plus W.L.L']);

        $booking = LimoBooking::query()->create(array_merge([
            'reference' => 'BK/15362',
            'customer_id' => $customer->id,
            'pickup_at' => '2026-09-26 15:59:00',
            'status' => LimoBooking::STATUS_QUEUE,
        ], $bookingAttrs));

        return LimoLeg::query()->create(array_merge([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 1,
            'reference' => '15362',
            'status' => LimoLeg::STATUS_QUEUE,
            'start_at' => '2026-09-26 15:59:00',
            'from_location' => 'Zinj H 1611',
            'to_location' => 'Bahrain airport',
            'rate' => 37, 'net_amount' => 37,
        ], $legAttrs));
    }

    public function test_a_trip_with_no_car_yet_still_shows_the_type_it_needs(): void
    {
        $this->leg(['vehicle_details' => 'Vito']);

        $html = Livewire::test(Bookings::class)->html();

        $this->assertStringContainsString('Vito', $html);
        // The button is still there — the type is what was asked for, not a car.
        $this->assertStringContainsString('Assign car', $html);
    }

    /** Imported bookings kept the type on the booking, not the leg. */
    public function test_the_type_falls_back_to_the_bookings_own_field(): void
    {
        $leg = $this->leg(['vehicle_details' => null], ['car_type' => 'SUV']);

        $this->assertSame('SUV', app(LimoQueueRows::class)->row($leg)['vehicle_type']);
    }

    /** The leg's own note is the more specific answer, so it wins. */
    public function test_the_legs_own_note_wins_over_the_bookings(): void
    {
        $leg = $this->leg(['vehicle_details' => 'Sedan'], ['car_type' => 'SUV']);

        $this->assertSame('Sedan', app(LimoQueueRows::class)->row($leg)['vehicle_type']);
    }

    /**
     * Once a real car is out, it is the headline — but the type stays under it,
     * so dispatch can see whether what was sent is what was asked for.
     */
    public function test_an_assigned_car_leads_and_the_type_stays_underneath(): void
    {
        $this->leg(['vehicle' => 'Ford Expedition', 'vehicle_details' => 'SUV']);

        $html = Livewire::test(Bookings::class)->html();

        $this->assertStringContainsString('Ford Expedition', $html);
        $this->assertStringContainsString('SUV', $html);
        $this->assertLessThan(mb_strpos($html, 'SUV'), mb_strpos($html, 'Ford Expedition'));
    }

    /** Nothing recorded either way still reads as nothing, not a blank line. */
    public function test_a_trip_with_neither_reads_as_before(): void
    {
        $leg = $this->leg(['vehicle_details' => null]);

        $this->assertSame('', app(LimoQueueRows::class)->row($leg)['vehicle_type']);
    }

    /** It is a screen fact, not a new spreadsheet column. */
    public function test_the_exports_gain_no_column(): void
    {
        $this->assertArrayNotHasKey('vehicle_type', app(LimoQueueRows::class)->headings());
    }
}
