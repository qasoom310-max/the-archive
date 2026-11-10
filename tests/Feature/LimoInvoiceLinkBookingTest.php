<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\InvoiceForm;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\LimoCombinedInvoicePdf;
use Tests\TestCase;

/**
 * An invoice that reached the system without its booking prints no trips.
 * The office links it to its booking from the invoice page.
 */
final class LimoInvoiceLinkBookingTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function customer(string $name = 'Braxtone Plus'): LimoCustomer
    {
        return LimoCustomer::query()->create(['name' => $name, 'type' => 'company']);
    }

    private function orphan(LimoCustomer $customer, float $total = 14): LimoInvoice
    {
        return LimoInvoice::query()->create([
            'customer_id' => $customer->id, 'issue_date' => '2026-09-20',
            'subtotal' => $total, 'total' => $total,
        ]);
    }

    private function booking(LimoCustomer $customer, float $fare = 14, string $from = 'Riffa'): LimoBooking
    {
        $booking = LimoBooking::query()->create(['customer_id' => $customer->id, 'fare' => $fare]);
        $booking->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'from_location' => $from,
            'to_location' => 'Bahrain airport', 'start_at' => '2026-09-20 10:00:00', 'days' => 1,
            'rate' => $fare, 'rate_basis' => 'trip', 'net_amount' => $fare,
        ]);

        return $booking;
    }

    public function test_only_matching_bookings_are_offered(): void
    {
        $customer = $this->customer();
        $invoice = $this->orphan($customer);
        $match = $this->booking($customer, 14);
        $otherFare = $this->booking($customer, 8);
        $otherCustomer = $this->booking($this->customer('Gulf Air'), 14);
        $alreadyBilled = $this->booking($customer, 14);
        $alreadyBilled->syncInvoice();

        Livewire::test(InvoiceForm::class, ['id' => $invoice->id])
            ->assertSeeHtml('data-invoice-link')
            ->assertSeeHtml('data-link-booking="' . $match->id . '"')
            ->assertDontSeeHtml('data-link-booking="' . $otherFare->id . '"')
            ->assertDontSeeHtml('data-link-booking="' . $otherCustomer->id . '"')
            ->assertDontSeeHtml('data-link-booking="' . $alreadyBilled->id . '"');
    }

    public function test_linking_puts_the_trips_on_the_combined_invoice(): void
    {
        $customer = $this->customer();
        $invoice = $this->orphan($customer);
        $booking = $this->booking($customer, 14, 'Riffa');
        LimoReceipt::query()->create([
            'customer_id' => $customer->id, 'booking_id' => $booking->id,
            'date' => '2026-09-20', 'amount' => 14, 'method' => 'cash',
        ]);

        $before = app(LimoCombinedInvoicePdf::class)->viewData(LimoInvoice::query()->whereKey($invoice->id)->get());
        $this->assertStringContainsString((string) $invoice->reference, $before['rows'][0]['service']);
        $this->assertSame('', $before['rows'][0]['trip']);

        Livewire::test(InvoiceForm::class, ['id' => $invoice->id])
            ->call('linkBooking', $booking->id)
            ->assertHasNoErrors()
            ->assertDontSeeHtml('data-invoice-link');

        $invoice->refresh();
        $this->assertSame($booking->id, $invoice->booking_id);
        // The booking's receipt now counts against this invoice.
        $this->assertEqualsWithDelta(14.0, (float) $invoice->amount_paid, 0.001);

        $after = app(LimoCombinedInvoicePdf::class)->viewData(LimoInvoice::query()->whereKey($invoice->id)->get());
        $this->assertSame('Riffa', $after['rows'][0]['from']);
        $this->assertSame((string) $booking->legs()->first()?->reference, $after['rows'][0]['trip']);
    }

    public function test_a_booking_that_does_not_match_cannot_be_linked(): void
    {
        $customer = $this->customer();
        $invoice = $this->orphan($customer, 14);
        $wrong = $this->booking($customer, 8);

        Livewire::test(InvoiceForm::class, ['id' => $invoice->id])
            ->call('linkBooking', $wrong->id)
            ->assertHasErrors('linkSearch');

        $this->assertNull($invoice->fresh()?->booking_id);
    }

    public function test_view_only_staff_cannot_link(): void
    {
        $customer = $this->customer();
        $invoice = $this->orphan($customer);
        $booking = $this->booking($customer);

        $this->actingAs(User::factory()->create());
        $this->grantEveryone('limousine.invoice');
        \App\Models\Auth\ModelAccess::query()->where('model', 'limousine.invoice')->update(['perm_write' => false, 'perm_create' => false, 'perm_unlink' => false]);

        Livewire::test(InvoiceForm::class, ['id' => $invoice->id])
            ->assertDontSeeHtml('data-invoice-link')
            ->call('linkBooking', $booking->id)
            ->assertForbidden();

        $this->assertNull($invoice->fresh()?->booking_id);
    }
}
