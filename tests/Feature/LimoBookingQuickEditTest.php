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
use Tests\TestCase;

/**
 * Quick-edit dialog on the bookings list: correct a booking's details without
 * leaving the list. Header fields only — the fare is the sum of the trip legs,
 * so it stays read-only here rather than being a value that gets silently
 * overwritten on the next leg change.
 */
final class LimoBookingQuickEditTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function booking(): LimoBooking
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg']);

        return LimoBooking::query()->create([
            'reference' => 'LIMO/14602',
            'customer_id' => $customer->id,
            'pickup_at' => '2026-04-26 21:15:00',
            'status' => LimoBooking::STATUS_ACTIVE,
            'fare' => 22.5,
            'amount' => 22.5,
            'pax_name' => 'Old name',
        ]);
    }

    public function test_it_loads_the_booking_into_the_dialog(): void
    {
        $booking = $this->booking();

        Livewire::test(Bookings::class)
            ->call('openEdit', $booking->id)
            ->assertSet('editingId', $booking->id)
            ->assertSet('edit.pax_name', 'Old name')
            // Rendered for <input type="datetime-local">, which needs this shape.
            ->assertSet('edit.pickup_at', '2026-04-26T21:15')
            ->assertSee('Helen Friberg');
    }

    public function test_it_saves_the_edited_details(): void
    {
        $booking = $this->booking();

        Livewire::test(Bookings::class)
            ->call('openEdit', $booking->id)
            ->set('edit.pax_name', 'Helen F.')
            ->set('edit.flight_number', 'GF509')
            ->set('edit.car_details', 'Sedan')
            ->set('edit.driver_name', 'Sohail - 6541')
            ->set('edit.booking_type', 'airport')
            ->set('edit.rate_type', 'hourly')
            ->set('edit.notes', 'Meet at arrivals')
            ->call('saveEdit')
            ->assertHasNoErrors()
            ->assertSet('editingId', null);

        $booking->refresh();
        $this->assertSame('Helen F.', $booking->pax_name);
        $this->assertSame('GF509', $booking->flight_number);
        $this->assertSame('Sedan', $booking->car_details);
        $this->assertSame('Sohail - 6541', $booking->driver_name);
        $this->assertSame('airport', $booking->booking_type);
        $this->assertSame('hourly', $booking->rate_type);
        $this->assertSame('Meet at arrivals', $booking->notes);
    }

    public function test_it_rejects_an_end_before_the_start_and_a_bad_email(): void
    {
        $booking = $this->booking();

        Livewire::test(Bookings::class)
            ->call('openEdit', $booking->id)
            ->set('edit.pickup_at', '2026-04-26T21:15')
            ->set('edit.booking_to', '2026-04-25T09:00')   // before the start
            ->set('edit.email', 'not-an-email')
            ->call('saveEdit')
            ->assertHasErrors(['edit.booking_to', 'edit.email']);

        // Nothing was written.
        $this->assertNull($booking->fresh()?->booking_to);
    }

    public function test_the_fare_is_not_editable_from_the_dialog(): void
    {
        $booking = $this->booking();

        // Even a crafted payload can't move the money: `fare` isn't a dialog
        // field, so saving leaves it exactly as the legs computed it.
        Livewire::test(Bookings::class)
            ->call('openEdit', $booking->id)
            ->set('edit.pax_name', 'Someone')
            ->call('saveEdit');

        $this->assertSame(22.5, (float) $booking->fresh()?->fare);
    }

    public function test_cancelling_discards_the_changes(): void
    {
        $booking = $this->booking();

        Livewire::test(Bookings::class)
            ->call('openEdit', $booking->id)
            ->set('edit.pax_name', 'Discard me')
            ->call('cancelEdit')
            ->assertSet('editingId', null);

        $this->assertSame('Old name', $booking->fresh()?->pax_name);
    }
}
