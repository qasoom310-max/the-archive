<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Livewire\QuotationForm;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Tests\TestCase;

/**
 * Roughly how long a journey takes, 1 to 12 hours. It is a note about the
 * trip and never part of the price — the money is driven by `hours`, which
 * an hourly or chauffeur job is billed by, and the two must not be confused.
 */
final class LimoLegEstimatedTimeTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'name' => 'Qassim']));
        app(ModuleManager::class)->install('limousine');
    }

    private function form(string $estimate): \Livewire\Features\SupportTesting\Testable
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg', 'phone' => '39000000']);

        return Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('pax_name', 'Helen Friberg')
            ->set('requested_by', 'Office')
            ->set('legs.0.from_location', 'Hotel')
            ->set('legs.0.to_location', 'Airport')
            ->set('legs.0.start_at', '2026-11-02T09:00')
            ->set('legs.0.car_details', 'Sedan')
            ->set('legs.0.rate', '20')
            ->set('legs.0.estimated_hours', $estimate);
    }

    public function test_a_trip_records_how_long_it_is_expected_to_take(): void
    {
        $component = $this->form('3');

        $this->assertSame(20.0, $component->instance()->grandTotal(), 'An estimate is not a multiplier.');

        $component->call('save')->assertHasNoErrors();

        $leg = LimoLeg::query()->sole();
        $this->assertSame(3, $leg->estimated_hours);
        $this->assertNull($leg->hours, 'The priced hours are a different field.');
        $this->assertSame(20.0, $leg->net_amount);
        $this->assertSame(20.0, LimoBooking::query()->sole()->fare);
    }

    /** Required on a transfer since 2026-10-21 (the owner's call). */
    public function test_a_transfer_cannot_be_saved_without_one(): void
    {
        $this->form('')->call('save')
            ->assertHasErrors(['legs.0.estimated_hours' => 'required'])
            ->assertSee('Choose roughly how long the trip takes.');

        $this->assertSame(0, LimoLeg::query()->count());
    }

    /** An old trip still to run is asked for one when it is edited... */
    public function test_an_open_trip_without_one_is_asked_for_it_on_edit(): void
    {
        $booking = $this->savedTrip(LimoLeg::STATUS_QUEUE);

        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->call('save')
            ->assertHasErrors(['legs.0.estimated_hours' => 'required']);
    }

    /** ...but a finished one, like the thousands imported without it, is not. */
    public function test_a_finished_trip_without_one_still_saves(): void
    {
        $booking = $this->savedTrip(LimoLeg::STATUS_COMPLETED);

        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(LimoLeg::query()->sole()->estimated_hours);
    }

    private function savedTrip(string $status): LimoBooking
    {
        $booking = LimoBooking::query()->create([
            'customer_id' => LimoCustomer::query()->create(['name' => 'Helen Friberg', 'phone' => '39000000'])->id,
            'pax_name' => 'Helen Friberg', 'requested_by' => 'Office', 'prepared_by' => 'Qassim',
            'status' => LimoBooking::STATUS_QUEUE,
        ]);
        $booking->legs()->create([
            'sequence' => 0, 'status' => $status, 'service_type' => LimoLeg::TYPE_TRANSFER,
            'start_at' => now()->subDay(), 'from_location' => 'Hotel', 'to_location' => 'Airport',
            'vehicle_details' => 'Sedan', 'rate' => 20, 'net_amount' => 20,
        ]);

        return $booking;
    }

    public function test_reopening_a_booking_shows_the_estimate_again(): void
    {
        $this->form('5')->call('save')->assertHasNoErrors();

        Livewire::test(BookingForm::class, ['id' => (int) LimoBooking::query()->sole()->id])
            ->assertSet('legs.0.estimated_hours', '5');
    }

    public function test_longer_than_twelve_hours_is_refused(): void
    {
        $this->form('13')->call('save')->assertHasErrors(['legs.0.estimated_hours']);

        $this->assertSame(0, LimoBooking::query()->count());
    }

    public function test_a_chauffeur_job_keeps_no_estimate(): void
    {
        // A chauffeur job is already measured in hours a day, so switching a
        // trip to one drops an estimate typed while it was a transfer.
        $this->form('4')
            ->set('legs.0.service_type', LimoLeg::TYPE_CHAUFFEUR)
            ->set('legs.0.hours', '8')
            ->set('legs.0.days', '2')
            ->call('save')
            ->assertHasNoErrors();

        $leg = LimoLeg::query()->sole();
        $this->assertNull($leg->estimated_hours);
        $this->assertSame(8.0, $leg->hours);
    }

    public function test_the_box_offers_one_to_twelve_hours_and_says_when_the_car_arrives(): void
    {
        $html = $this->form('3')->html();

        $this->assertStringContainsString('Estimated time', $html);
        $this->assertStringContainsString('>12 hours<', $html);
        $this->assertStringContainsString('>1 hour<', $html);
        $this->assertStringNotContainsString('>13 hours<', $html);
        // 09:00 plus three hours.
        $this->assertStringContainsString('12:00 PM', $html);
    }

    public function test_a_quotation_asks_the_same_question(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Braxtone', 'phone' => '39111111']);

        Livewire::test(QuotationForm::class)
            ->set('customer_id', $customer->id)
            ->set('requested_by', 'Office')
            ->set('legs.0.from_location', 'Manama')
            ->set('legs.0.to_location', 'Airport')
            ->set('legs.0.start_at', '2026-11-02T09:00')
            ->set('legs.0.rate', '30')
            ->set('legs.0.estimated_hours', '2')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, LimoLeg::query()->sole()->estimated_hours);
    }
}
