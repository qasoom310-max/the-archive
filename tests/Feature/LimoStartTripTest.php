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
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoLeg;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * Pressing Start trip on a trip that has nobody driving it.
 *
 * A trip cannot go out without a car and a driver. The button used to refuse
 * with a note telling the office to go and assign one — sending them to another
 * button, and then a third, to do what they had just asked for. It now opens the
 * dialog that does both, and the press it came from still happens: naming the
 * two sends the trip out.
 */
final class LimoStartTripTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function leg(?int $carId = null, ?int $driverId = null): LimoLeg
    {
        $customer = LimoCustomer::query()->create(['name' => 'Amina Mohamed Mansoori']);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00003',
            'customer_id' => $customer->id,
            'pickup_at' => now()->addDay(),
            'status' => LimoBooking::STATUS_CONFIRMED,
        ]);

        return LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 0,
            'reference' => '10004',
            'status' => LimoLeg::STATUS_CONFIRMED,
            'start_at' => now()->addDay(),
            'from_location' => 'Hotel',
            'to_location' => 'Bahrain Airport',
            'car_id' => $carId,
            'driver_id' => $driverId,
            'rate' => 12, 'net_amount' => 12,
        ]);
    }

    private function car(): Vehicle
    {
        return Vehicle::query()->create([
            'name' => 'Mercedes E-Class',
            'plate_number' => '123456',
            'make' => 'Mercedes',
            'model' => 'E-Class',
            'status' => 'available',
        ]);
    }

    private function driver(): LimoDriver
    {
        return LimoDriver::query()->create(['name' => 'Rashid', 'active' => true]);
    }

    /** The whole point: one press opens the place where both are named. */
    public function test_start_trip_opens_the_assign_dialog_when_nobody_is_on_it(): void
    {
        $leg = $this->leg();

        Livewire::test(Bookings::class)
            ->call('advanceLeg', $leg->id, LimoLeg::STATUS_ACTIVE)
            ->assertSet('assigningId', $leg->id)
            ->assertSet('startAfterAssign', true)
            ->assertSee('The trip starts as soon as a car and a driver are named.');

        // And it did not quietly start without a crew.
        $this->assertSame(LimoLeg::STATUS_CONFIRMED, $leg->fresh()?->status);
    }

    public function test_naming_both_in_that_dialog_starts_the_trip(): void
    {
        $leg = $this->leg();

        Livewire::test(Bookings::class)
            ->call('advanceLeg', $leg->id, LimoLeg::STATUS_ACTIVE)
            ->set('assignCar', (string) $this->car()->id)
            ->set('assignDriver', (string) $this->driver()->id)
            ->call('saveAssign')
            ->assertSet('assigningId', null);

        $fresh = $leg->fresh();
        $this->assertSame(LimoLeg::STATUS_ACTIVE, $fresh?->status);
        $this->assertNotNull($fresh?->car_id);
        $this->assertNotNull($fresh?->driver_id);
        // The booking follows its legs.
        $this->assertSame(LimoBooking::STATUS_ACTIVE, LimoBooking::query()->find($leg->legable_id)?->status);
    }

    /** A car but no driver keeps the dialog open, on the picker that is empty. */
    public function test_a_missing_driver_is_said_in_the_dialog_not_somewhere_else(): void
    {
        $leg = $this->leg();

        Livewire::test(Bookings::class)
            ->call('advanceLeg', $leg->id, LimoLeg::STATUS_ACTIVE)
            ->set('assignCar', (string) $this->car()->id)
            ->call('saveAssign')
            ->assertHasErrors('assignDriver')
            // Still open, so the driver can be picked without starting over.
            ->assertSet('assigningId', $leg->id);

        // The car was kept rather than thrown away with the attempt.
        $this->assertNotNull($leg->fresh()?->car_id);
        $this->assertSame(LimoLeg::STATUS_CONFIRMED, $leg->fresh()?->status);
    }

    public function test_a_missing_car_is_said_the_same_way(): void
    {
        $leg = $this->leg();

        Livewire::test(Bookings::class)
            ->call('advanceLeg', $leg->id, LimoLeg::STATUS_ACTIVE)
            ->set('assignDriver', (string) $this->driver()->id)
            ->call('saveAssign')
            ->assertHasErrors('assignCar')
            ->assertSet('assigningId', $leg->id);

        $this->assertSame(LimoLeg::STATUS_CONFIRMED, $leg->fresh()?->status);
    }

    /** Already crewed → the button does what it always did, in one press. */
    public function test_start_trip_still_just_starts_a_crewed_trip(): void
    {
        $leg = $this->leg($this->car()->id, $this->driver()->id);

        Livewire::test(Bookings::class)
            ->call('advanceLeg', $leg->id, LimoLeg::STATUS_ACTIVE)
            ->assertSet('assigningId', null);

        $this->assertSame(LimoLeg::STATUS_ACTIVE, $leg->fresh()?->status);
    }

    /**
     * Assigning a car alone, from the Assign car button, is still allowed —
     * the crew can be settled in two sittings when the office wants to.
     */
    public function test_assigning_only_a_car_from_the_assign_button_is_still_fine(): void
    {
        $leg = $this->leg();

        Livewire::test(Bookings::class)
            ->call('openAssign', $leg->id)
            ->assertSet('startAfterAssign', false)
            ->set('assignCar', (string) $this->car()->id)
            ->call('saveAssign')
            ->assertHasNoErrors()
            ->assertSet('assigningId', null);

        $this->assertNotNull($leg->fresh()?->car_id);
        // Not dispatched: nobody is driving it yet.
        $this->assertSame(LimoLeg::STATUS_CONFIRMED, $leg->fresh()?->status);
    }

    /** A closed trip is not started, and no dialog is offered for it. */
    public function test_a_finished_trip_cannot_be_started(): void
    {
        $leg = $this->leg();
        $leg->forceFill(['status' => LimoLeg::STATUS_COMPLETED])->save();

        Livewire::test(Bookings::class)
            ->call('advanceLeg', $leg->id, LimoLeg::STATUS_ACTIVE)
            ->assertSet('assigningId', null)
            ->assertSet('startAfterAssign', false);

        $this->assertSame(LimoLeg::STATUS_COMPLETED, $leg->fresh()?->status);
    }

    /** Closing the dialog forgets the intent, so the next open is a plain one. */
    public function test_closing_forgets_that_it_was_opened_to_start(): void
    {
        $leg = $this->leg();

        Livewire::test(Bookings::class)
            ->call('advanceLeg', $leg->id, LimoLeg::STATUS_ACTIVE)
            ->assertSet('startAfterAssign', true)
            ->call('closeAssign')
            ->assertSet('startAfterAssign', false)
            ->call('openAssign', $leg->id)
            ->assertSet('startAfterAssign', false);
    }
}
