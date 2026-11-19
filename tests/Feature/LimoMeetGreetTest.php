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
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\LimoCombinedInvoicePdf;
use Modules\Limousine\Services\LimoInvoicePdf;
use Modules\Limousine\Services\LimoQueueRows;
use Modules\Limousine\Services\LimoReceiptPdf;
use Modules\Limousine\Services\TripCancellation;
use Tests\TestCase;

/**
 * Meet & greet on a booking: a tick box that opens a name and a price
 * (5 BHD to start). The price is added to the booking total, once.
 */
final class LimoMeetGreetTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'name' => 'Qassim']));
        app(ModuleManager::class)->install('limousine');
    }

    private function form(): \Livewire\Features\SupportTesting\Testable
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg', 'phone' => '39000000']);

        return Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('pax_name', 'Helen Friberg')
            ->set('requested_by', 'Office')
            ->set('legs.0.from_location', 'Airport')
            ->set('legs.0.to_location', 'Hotel')
            ->set('legs.0.estimated_hours', '1')
            ->set('legs.0.start_at', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('legs.0.car_details', 'Sedan')
            ->set('legs.0.rate', '20');
    }

    public function test_ticking_it_opens_the_name_and_a_5_bhd_price(): void
    {
        $this->form()
            ->assertDontSeeHtml('data-meet-greet-fields')
            ->set('meet_greet', true)
            ->assertSeeHtml('data-meet-greet-fields')
            ->assertSet('meet_greet_price', '5')
            ->assertSet('meet_greet_name', 'Helen Friberg');
    }

    public function test_the_price_is_added_to_the_total_and_can_be_changed(): void
    {
        $page = $this->form()->set('meet_greet', true)->set('meet_greet_name', 'Mr Helen')->set('meet_greet_price', '7.5');
        $this->assertSame(27.5, $page->instance()->grandTotal());

        $page->call('save')->assertHasNoErrors();

        $booking = LimoBooking::query()->sole();
        $this->assertTrue($booking->meet_greet);
        $this->assertSame('Mr Helen', $booking->meet_greet_name);
        $this->assertSame(7.5, $booking->meet_greet_price);
        $this->assertSame(27.5, $booking->fare);
        $this->assertSame(27.5, (float) LimoInvoice::query()->sole()->total);

        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->assertSet('meet_greet', true)
            ->assertSet('meet_greet_name', 'Mr Helen')
            ->assertSet('meet_greet_price', '7.5');
    }

    public function test_unticked_it_costs_nothing_and_keeps_nothing(): void
    {
        $this->form()->set('meet_greet', true)->set('meet_greet', false)->call('save')->assertHasNoErrors();

        $booking = LimoBooking::query()->sole();
        $this->assertFalse($booking->meet_greet);
        $this->assertNull($booking->meet_greet_price);
        $this->assertSame(20.0, $booking->fare);
    }

    public function test_a_name_and_price_are_needed_when_ticked(): void
    {
        $this->form()->set('meet_greet', true)->set('meet_greet_name', '')->set('meet_greet_price', '')
            ->call('save')->assertHasErrors(['meet_greet_name', 'meet_greet_price']);
    }

    public function test_the_invoices_print_it_as_its_own_line(): void
    {
        $this->form()->set('meet_greet', true)->set('meet_greet_name', 'Mr Helen')->call('save')->assertHasNoErrors();
        $invoice = LimoInvoice::query()->sole();

        $lines = app(LimoInvoicePdf::class)->viewData($invoice)['lines'];
        $this->assertCount(2, $lines);
        $this->assertSame(20.0, $lines[0]['amount']);
        $this->assertSame('Meet & greet', $lines[1]['service']);
        $this->assertSame('Mr Helen', $lines[1]['from']);
        $this->assertSame(5.0, $lines[1]['amount']);

        // The combined invoice adds up without an "Adjustment" line.
        $rows = app(LimoCombinedInvoicePdf::class)->rows(LimoInvoice::query()->get());
        $this->assertSame(['20', '5'], array_map(static fn (array $r): string => (string) $r['total'], $rows));
        $this->assertSame('Meet & greet', $rows[1]['service']);
    }

    public function test_the_receipt_lists_it_under_the_trips(): void
    {
        $this->form()->set('meet_greet', true)->set('meet_greet_name', 'Mr Helen')->call('save')->assertHasNoErrors();
        $booking = LimoBooking::query()->sole();
        $receipt = LimoReceipt::query()->create([
            'customer_id' => $booking->customer_id, 'booking_id' => $booking->id,
            'date' => now(), 'amount' => 25, 'method' => 'cash',
        ]);

        $trips = app(LimoReceiptPdf::class)->viewData($receipt)['trips'];
        $this->assertCount(2, $trips);
        $this->assertSame('Meet & greet: Mr Helen', $trips[1]['route']);
        $this->assertStringContainsString('%PDF', app(LimoReceiptPdf::class)->render($receipt));

        // Unticked, the receipt lists the trip alone.
        $booking->forceFill(['meet_greet' => false])->save();
        $this->assertCount(1, app(LimoReceiptPdf::class)->viewData($receipt->refresh())['trips']);
    }

    public function test_the_driver_copy_says_meet_and_greet(): void
    {
        $this->form()->set('meet_greet', true)->set('meet_greet_name', 'Mr Helen')->call('save')->assertHasNoErrors();

        $text = app(LimoQueueRows::class)->whatsappText(LimoLeg::query()->sole());
        $this->assertStringContainsString('Meet & greet: Mr Helen', $text);
    }

    public function test_a_booking_with_every_trip_cancelled_is_not_charged_for_it(): void
    {
        $this->form()->set('meet_greet', true)->call('save')->assertHasNoErrors();
        $leg = LimoLeg::query()->sole();

        app(TripCancellation::class)->cancel($leg, 'Customer changed plans');

        $this->assertSame(0.0, LimoBooking::query()->sole()->fare);
    }
}
