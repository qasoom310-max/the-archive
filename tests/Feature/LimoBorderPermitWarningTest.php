<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Support\BorderPermitCheck;
use Modules\Rental\Models\TravelPermit;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * Assigning a car and a driver to a limousine trip that leaves Bahrain warns
 * when that driver holds no travel authorization for that car on the day.
 * Finding out at the causeway costs the trip; the office is told while there
 * is still time to make one. A warning, never a block.
 */
final class LimoBorderPermitWarningTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 10:00:00');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
        app(ModuleManager::class)->install('limousine');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function leg(string $to = 'SA', string $from = 'BH', string $at = '2026-11-20 08:00:00'): LimoLeg
    {
        static $ref = 20000;
        $customer = LimoCustomer::query()->firstOrCreate(['name' => 'Amina Mohamed Mansoori']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/' . $ref, 'customer_id' => $customer->id, 'pickup_at' => $at, 'status' => LimoBooking::STATUS_CONFIRMED,
        ]);

        return LimoLeg::query()->create([
            'legable_type' => LimoBooking::class, 'legable_id' => $booking->id, 'sequence' => 0,
            'reference' => (string) $ref++, 'status' => LimoLeg::STATUS_CONFIRMED, 'start_at' => $at,
            'from_location' => 'Manama', 'from_country' => $from, 'to_location' => 'Khobar', 'to_country' => $to,
            'rate' => 60, 'net_amount' => 60,
        ]);
    }

    private function car(): Vehicle
    {
        return Vehicle::query()->create(['name' => 'Ford Territory', 'plate_no' => '111705', 'make' => 'Ford', 'model' => 'Territory', 'status' => 'available']);
    }

    private function driver(): LimoDriver
    {
        return LimoDriver::query()->create(['name' => 'Qassim Fuad Makhlooq', 'cpr' => '870300628', 'active' => true]);
    }

    private function permit(Vehicle $car, LimoDriver $driver, string $from, string $to): TravelPermit
    {
        return TravelPermit::query()->create([
            'vehicle_id' => $car->id, 'driver_id' => $driver->id, 'issued_on' => $from, 'valid_from' => $from, 'valid_to' => $to,
            'period' => '7d', 'plate_no' => '111705', 'make' => 'Ford', 'model' => 'Territory', 'year' => 2025, 'color' => 'White',
            'driver_name' => 'Qassim Fuad Makhlooq', 'driver_cpr' => '870300628', 'owner_name' => 'Owner', 'owner_no' => '1',
        ]);
    }

    public function test_a_trip_leaving_bahrain_warns_when_the_driver_holds_no_letter_for_the_car(): void
    {
        $check = app(BorderPermitCheck::class);
        $car = $this->car();
        $driver = $this->driver();

        $warning = $check->warning($this->leg(), $car->id, $driver->id);
        $this->assertNotNull($warning);
        $this->assertStringContainsString('no travel authorization', $warning['message']);

        // A letter covering the day of the trip: nothing to say.
        $this->permit($car, $driver, '2026-11-18', '2026-11-25');
        $this->assertNull($check->warning($this->leg(), $car->id, $driver->id));
    }

    public function test_a_letter_that_has_ended_or_not_started_does_not_count(): void
    {
        $check = app(BorderPermitCheck::class);
        $car = $this->car();
        $driver = $this->driver();

        $this->permit($car, $driver, '2026-11-01', '2026-11-10');
        $ended = $check->warning($this->leg(), $car->id, $driver->id);
        $this->assertStringContainsString('ended on 10/11/2026', (string) ($ended['message'] ?? ''));

        TravelPermit::query()->delete();
        $this->permit($car, $driver, '2026-12-01', '2026-12-30');
        $later = $check->warning($this->leg(), $car->id, $driver->id);
        $this->assertStringContainsString('only starts on 01/12/2026', (string) ($later['message'] ?? ''));
    }

    /** The letter is for one driver in one car. */
    public function test_a_letter_for_another_car_or_another_driver_does_not_count(): void
    {
        $check = app(BorderPermitCheck::class);
        $car = $this->car();
        $driver = $this->driver();
        $otherCar = Vehicle::query()->create(['name' => 'Kia', 'plate_no' => '9', 'status' => 'available']);
        $otherDriver = LimoDriver::query()->create(['name' => 'Ali Hasan Makhlooq', 'active' => true]);

        $this->permit($otherCar, $driver, '2026-11-01', '2026-12-30');
        $this->permit($car, $otherDriver, '2026-11-01', '2026-12-30');

        $this->assertNotNull($check->warning($this->leg(), $car->id, $driver->id));
    }

    public function test_a_trip_inside_bahrain_or_with_no_country_recorded_asks_nothing(): void
    {
        $check = app(BorderPermitCheck::class);
        $car = $this->car();
        $driver = $this->driver();

        $this->assertNull($check->warning($this->leg(to: 'BH'), $car->id, $driver->id));

        $unknown = $this->leg();
        $unknown->forceFill(['from_country' => null, 'to_country' => null])->save();
        $this->assertNull($check->warning($unknown, $car->id, $driver->id));

        // Coming back in counts as crossing too.
        $this->assertNotNull($check->warning($this->leg(to: 'BH', from: 'SA'), $car->id, $driver->id));
        // Nothing to check until both are chosen.
        $this->assertNull($check->warning($this->leg(), $car->id, null));
        $this->assertNull($check->warning($this->leg(), null, $driver->id));
    }

    public function test_the_assign_dialog_shows_the_warning_and_still_saves(): void
    {
        $car = $this->car();
        $driver = $this->driver();
        $leg = $this->leg();

        Livewire::test(Bookings::class)
            ->call('openAssign', $leg->id)
            ->assertDontSeeHtml('data-permit-warning')
            ->set('assignCar', (string) $car->id)
            ->set('assignDriver', (string) $driver->id)
            ->assertSeeHtml('data-permit-warning')
            ->assertSee('no travel authorization for this car')
            ->assertSee('Make a travel authorization')
            ->call('saveAssign')
            ->assertHasNoErrors()
            // Said again beside the confirmation, now that the dialog has closed.
            ->assertDontSeeHtml('data-permit-warning')
            ->assertSee('no travel authorization for this car');

        // A warning, not a lock: the trip is assigned.
        $leg->refresh();
        $this->assertSame([$car->id, $driver->id], [$leg->car_id, $leg->driver_id]);
    }

    public function test_the_dialog_is_quiet_when_the_driver_holds_a_letter_or_the_car_is_another_companys(): void
    {
        $car = $this->car();
        $driver = $this->driver();
        $this->permit($car, $driver, '2026-11-01', '2026-12-30');
        $leg = $this->leg();

        Livewire::test(Bookings::class)
            ->call('openAssign', $leg->id)
            ->set('assignCar', (string) $car->id)
            ->set('assignDriver', (string) $driver->id)
            ->assertDontSeeHtml('data-permit-warning');

        TravelPermit::query()->delete();

        // Another company's car comes with that company's own paper.
        Livewire::test(Bookings::class)
            ->call('openAssign', $leg->id)
            ->set('assignCarSource', 'outside')
            ->set('assignDriver', (string) $driver->id)
            ->assertDontSeeHtml('data-permit-warning');
    }
}
