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
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\BookingPayments;
use Tests\TestCase;

/**
 * A trip's 3-dot menu opens its booking's invoice and receipts.
 */
final class LimoBookingRowPaperworkTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('limousine');
    }

    private function booking(): LimoBooking
    {
        $booking = LimoBooking::query()->create([
            'customer_id' => LimoCustomer::query()->create(['name' => 'Faris'])->id,
            'status' => LimoBooking::STATUS_QUEUE,
        ]);
        LimoLeg::query()->create([
            'legable_type' => LimoBooking::class, 'legable_id' => $booking->id, 'sequence' => 1,
            'status' => LimoLeg::STATUS_QUEUE, 'start_at' => now()->addDay(),
            'from_location' => 'Seef', 'to_location' => 'Bahrain Airport', 'rate' => 100, 'net_amount' => 100,
        ]);
        $booking->recalcTotal();
        $booking->save();

        return $booking->refresh();
    }

    public function test_the_menu_opens_the_bookings_invoice_and_each_receipt(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $booking = $this->booking();
        app(BookingPayments::class)->receive($booking, 30);
        app(BookingPayments::class)->receive($booking->refresh(), 20);

        $invoice = LimoInvoice::query()->where('booking_id', $booking->id)->firstOrFail();
        // A receipt written against the invoice alone belongs to the booking too.
        $extra = LimoReceipt::query()->create([
            'invoice_id' => $invoice->id, 'customer_id' => $booking->customer_id, 'date' => now()->toDateString(), 'amount' => 5,
        ]);
        $receipts = LimoReceipt::query()->where('booking_id', $booking->id)->pluck('id')->push($extra->id);
        $this->assertCount(3, $receipts);

        $page = Livewire::test(Bookings::class)->set('tab', 'all')
            ->assertSee('Open invoice')
            ->assertSeeHtml('href="' . url('/app/limousine/invoice/' . $invoice->id) . '"')
            ->assertSee((string) $invoice->reference);

        foreach ($receipts as $id) {
            $page->assertSeeHtml('href="' . url('/app/limousine/receipt/' . $id) . '"');
        }
    }

    public function test_a_booking_without_paperwork_shows_neither(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $booking = $this->booking();
        LimoInvoice::query()->where('booking_id', $booking->id)->delete();

        Livewire::test(Bookings::class)->set('tab', 'all')
            ->assertDontSeeHtml('data-open-invoice')
            ->assertDontSeeHtml('data-open-receipt');
    }

    public function test_someone_who_cannot_read_invoices_or_receipts_is_not_offered_them(): void
    {
        $this->grantEveryone('limousine.booking');
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $booking = $this->booking();
        app(BookingPayments::class)->receive($booking, 30);

        Livewire::test(Bookings::class)->set('tab', 'all')
            ->assertDontSeeHtml('data-open-invoice')
            ->assertDontSeeHtml('data-open-receipt');

        $this->grantEveryone('limousine.invoice', 'limousine.receipt');

        Livewire::test(Bookings::class)->set('tab', 'all')
            ->assertSeeHtml('data-open-invoice')
            ->assertSeeHtml('data-open-receipt');
    }
}
