<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\TripCrew;
use Tests\TestCase;

/**
 * Active, completed and cancelled trips are locked (the owner's rule,
 * 2026-11-24): staff keep comments and payments, never the job's terms; the
 * owner and the Accountant may correct with a logged reason.
 */
final class LimoTripLockTest extends TestCase
{
    use DatabaseMigrations;

    private LimoBooking $booking;

    private LimoLeg $leg;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');
        app(ModuleManager::class)->install('limousine');
        $this->grantEveryone('limousine.booking');

        $this->booking = LimoBooking::query()->create([
            'customer_id' => LimoCustomer::query()->create(['name' => 'Demo Co'])->id,
            'fare' => 30, 'pax_name' => 'Mr Demo', 'status' => LimoBooking::STATUS_COMPLETED,
            'requested_by' => 'Customer', 'prepared_by' => 'Office',
        ]);
        $this->leg = $this->booking->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'status' => LimoLeg::STATUS_COMPLETED,
            'from_location' => 'Hotel', 'to_location' => 'Airport', 'start_at' => now()->subDay(), 'days' => 1,
            'rate' => 30, 'rate_basis' => 'trip', 'net_amount' => 30, 'estimated_hours' => 1, 'vehicle_details' => 'Sedan',
        ]);
    }

    private function staff(): User
    {
        return User::factory()->create(['name' => 'Staff']);
    }

    public function test_staff_cannot_change_a_completed_trip_but_can_write_comments(): void
    {
        $this->actingAs($this->staff());

        Livewire::test(BookingForm::class, ['id' => $this->booking->id])
            ->assertSeeHtml('data-booking-locked')
            ->assertSeeHtml('data-leg-locked')
            ->set('pax_name', 'Someone Else')
            ->call('save')
            ->assertHasErrors('correctionReason');
        $this->assertSame('Mr Demo', $this->booking->fresh()?->pax_name);

        Livewire::test(BookingForm::class, ['id' => $this->booking->id])
            ->set('legs.0.from_location', 'Elsewhere')
            ->call('save')
            ->assertHasErrors('correctionReason');
        $this->assertSame('Hotel', $this->leg->fresh()?->from_location);

        Livewire::test(BookingForm::class, ['id' => $this->booking->id])
            ->set('notes', 'Customer asked for a receipt copy')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('Customer asked for a receipt copy', $this->booking->fresh()?->notes);
    }

    public function test_the_accountant_corrects_with_a_reason_that_is_logged(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Huda', 'is_accountant' => true]));

        Livewire::test(BookingForm::class, ['id' => $this->booking->id])
            ->assertSeeHtml('data-correction-reason')
            ->set('pax_name', 'Mrs Demo')
            ->call('save')->assertHasErrors('correctionReason')
            ->set('correctionReason', 'Wrong passenger name on the invoice')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('Mrs Demo', $this->booking->fresh()?->pax_name);
        $this->assertDatabaseHas('activity_logs', ['action' => 'booking_corrected']);
    }

    public function test_staff_cannot_cancel_or_reassign_a_completed_trip(): void
    {
        $this->actingAs($this->staff());

        Livewire::test(BookingForm::class, ['id' => $this->booking->id])->call('cancelBooking');
        $this->assertSame(LimoLeg::STATUS_COMPLETED, $this->leg->fresh()?->status);

        $driver = LimoDriver::query()->create(['name' => 'Sam']);
        $result = app(TripCrew::class)->assign($this->leg->fresh() ?? $this->leg, null, (int) $driver->id);
        $this->assertNotSame([], $result['errors']);
        $this->assertNull($this->leg->fresh()?->driver_id);
    }

    public function test_an_upcoming_trip_stays_editable(): void
    {
        $this->leg->forceFill(['status' => LimoLeg::STATUS_CONFIRMED, 'start_at' => now()->addDay()])->save();
        $this->booking->forceFill(['status' => LimoBooking::STATUS_CONFIRMED])->save();
        $this->actingAs($this->staff());

        Livewire::test(BookingForm::class, ['id' => $this->booking->id])
            ->assertDontSeeHtml('data-booking-locked')
            ->set('pax_name', 'Someone Else')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('Someone Else', $this->booking->fresh()?->pax_name);
    }
}
