<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Tests\TestCase;

/**
 * Units on a booking: two cars for the same trip cost twice the price.
 */
final class LimoBookingUnitsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'name' => 'Qassim']));
        app(ModuleManager::class)->install('limousine');
    }

    private function form(string $units): \Livewire\Features\SupportTesting\Testable
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg', 'phone' => '39000000']);

        return Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('pax_name', 'Helen Friberg')
            ->set('requested_by', 'Office')
            ->set('units', $units)
            ->set('legs.0.from_location', 'Hotel')
            ->set('legs.0.to_location', 'Airport')
            ->set('legs.0.estimated_hours', '2')
            ->set('legs.0.start_at', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('legs.0.car_details', 'Sedan')
            ->set('legs.0.rate', '20');
    }

    public function test_a_new_booking_has_one_unit(): void
    {
        $this->assertSame('1', Livewire::test(BookingForm::class)->get('units'));
    }

    public function test_two_units_double_the_price(): void
    {
        $component = $this->form('2');
        $this->assertSame(40.0, $component->instance()->grandTotal());

        $component->call('save')->assertHasNoErrors();

        $booking = LimoBooking::query()->sole();
        $this->assertSame(40.0, $booking->fare);
        $this->assertSame(2, $booking->num_cars);
        $leg = LimoLeg::query()->sole();
        $this->assertSame(2, $leg->units);
        $this->assertSame(40.0, $leg->net_amount);
    }

    public function test_one_unit_keeps_the_price(): void
    {
        $this->form('1')->call('save')->assertHasNoErrors();

        $this->assertSame(20.0, LimoBooking::query()->sole()->fare);
    }

    public function test_units_must_be_at_least_one(): void
    {
        $this->form('0')->call('save')->assertHasErrors(['units']);
    }

    public function test_reopening_a_booking_shows_its_units(): void
    {
        $this->form('3')->call('save')->assertHasNoErrors();
        $booking = LimoBooking::query()->sole();

        Livewire::test(BookingForm::class, ['id' => $booking->id])->assertSet('units', '3');
    }

    public function test_editing_a_trip_from_the_list_keeps_its_units(): void
    {
        $this->form('2')->call('save')->assertHasNoErrors();
        $leg = LimoLeg::query()->sole();

        Livewire::test(Bookings::class)
            ->call('openEdit', $leg->legable_id, $leg->id)
            ->set('editLeg.rate', '25')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertSame(50.0, $leg->refresh()->net_amount);
        $this->assertSame(50.0, LimoBooking::query()->sole()->fare);
    }
}
