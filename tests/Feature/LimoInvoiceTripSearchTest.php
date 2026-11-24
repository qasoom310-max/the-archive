<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Invoices;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\BulkInvoices;
use Tests\TestCase;

/**
 * The Invoices list finds a bill by the trip number the office quotes (what
 * the PDF prints as "Ref. #"), and says why a trip's invoice is not there.
 */
final class LimoInvoiceTripSearchTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function booking(LimoCustomer $customer, string $day = '2026-10-10'): LimoBooking
    {
        $booking = LimoBooking::query()->create(['customer_id' => $customer->id, 'fare' => 50]);
        $booking->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'from_location' => 'Airport',
            'to_location' => 'Hotel', 'start_at' => $day . ' 09:00:00', 'days' => 1,
            'rate' => 50, 'rate_basis' => 'trip', 'net_amount' => 50,
        ]);

        return $booking;
    }

    private function tripNumber(LimoBooking $booking): string
    {
        return (string) $booking->legs()->value('reference');
    }

    public function test_a_trip_number_finds_its_invoice_and_the_row_shows_it(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Braxtone', 'type' => 'company']);
        $booking = $this->booking($customer);
        $invoice = $booking->syncInvoice();
        $other = $this->booking($customer)->syncInvoice();
        $trip = $this->tripNumber($booking);

        Livewire::test(Invoices::class)
            ->set('search', $trip)
            ->assertSee((string) $invoice->reference)
            ->assertDontSee((string) $other->reference)
            ->assertSeeHtml('data-invoice-trips')
            ->assertSeeHtml('data-trip-hint');
    }

    public function test_the_number_a_trip_had_before_the_renumbering_still_finds_it(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Braxtone', 'type' => 'company']);
        $booking = $this->booking($customer);
        $invoice = $booking->syncInvoice();
        LimoLeg::query()->where('legable_id', $booking->id)->update(['previous_reference' => '41697']);

        Livewire::test(Invoices::class)
            ->set('search', '41697')
            ->assertSee((string) $invoice->reference);
    }

    public function test_an_old_system_invoice_listing_the_booking_is_found(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Braxtone', 'type' => 'company']);
        $booking = $this->booking($customer);
        $legacy = new LimoInvoice();
        $legacy->forceFill([
            'customer_id' => $customer->id, 'issue_date' => '2026-10-11', 'total' => 50, 'amount_paid' => 0,
            'status' => LimoInvoice::STATUS_UNPAID, 'notes' => 'Invoice #1327 | Bookings: 999, ' . $booking->id,
        ])->save();

        Livewire::test(Invoices::class)
            ->set('search', $this->tripNumber($booking))
            ->assertSee((string) $legacy->reference);
    }

    public function test_a_trip_with_no_invoice_says_so(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Braxtone', 'type' => 'company']);
        $booking = $this->booking($customer);

        Livewire::test(Invoices::class)
            ->set('search', $this->tripNumber($booking))
            ->assertSee('has no invoice yet.')
            ->assertSee('Open the booking to create one');
    }

    public function test_a_trip_already_in_a_bulk_invoice_says_so(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Braxtone', 'type' => 'company']);
        $booking = $this->booking($customer);
        $invoice = $booking->syncInvoice();
        app(BulkInvoices::class)->create([$invoice->id], '', '', (int) auth()->id());

        Livewire::test(Invoices::class)
            ->set('search', $this->tripNumber($booking))
            ->assertSee('already in bulk invoice BINV/00001');
    }

    public function test_a_trip_outside_the_dates_picked_says_so(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Braxtone', 'type' => 'company']);
        $booking = $this->booking($customer, '2026-10-10');
        $invoice = $booking->syncInvoice();

        Livewire::test(Invoices::class)
            ->set('from', '2026-11-01')
            ->set('to', '2026-11-30')
            ->set('search', $this->tripNumber($booking))
            ->assertDontSee('Trip ' . $this->tripNumber($booking) . '</div>', false)
            ->assertSee('outside the dates or tab you picked');
        $this->assertNotNull($invoice->reference);
    }
}
