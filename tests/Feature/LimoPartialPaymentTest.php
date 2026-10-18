<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Livewire\LimoHome;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\LimoQueueRows;
use Tests\TestCase;

/**
 * A booking with some money taken and some still owed reads PARTIAL PAID —
 * never "paid" because a flag somewhere said so (a re-priced invoice, an
 * import's Payment column, "Mark paid").
 */
final class LimoPartialPaymentTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function booking(float $fare, float $advance, string $flag): LimoBooking
    {
        $customer = LimoCustomer::query()->create(['name' => 'Ahmed Ali']);
        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id,
            'pickup_at' => now()->addDay(),
            'status' => LimoBooking::STATUS_QUEUE,
            'advance' => $advance,
            'payment_status' => $flag,
        ]);
        LimoLeg::query()->create([
            'legable_type' => LimoBooking::class, 'legable_id' => $booking->id, 'sequence' => 0,
            'status' => LimoLeg::STATUS_QUEUE, 'start_at' => now()->addDay(),
            'from_location' => 'Seef', 'to_location' => 'Bahrain Airport',
            'rate' => $fare, 'net_amount' => $fare,
        ]);
        // The fare is written straight, so no save hook re-reads the flag.
        LimoBooking::query()->whereKey($booking->id)->update(['fare' => $fare, 'amount' => $fare, 'payment_status' => $flag]);

        return $booking->refresh();
    }

    private function invoice(LimoBooking $booking, float $paid): void
    {
        $invoice = new LimoInvoice;
        $invoice->forceFill([
            'booking_id' => $booking->id, 'customer_id' => $booking->customer_id,
            'issue_date' => now()->toDateString(), 'total' => (float) $booking->fare, 'amount_paid' => $paid,
            'status' => $paid + 0.0005 >= (float) $booking->fare ? LimoInvoice::STATUS_PAID : LimoInvoice::STATUS_PARTIAL,
        ])->saveQuietly();
    }

    /** @return array<string, mixed> */
    private function row(LimoBooking $booking): array
    {
        $rows = app(LimoQueueRows::class)->all('all', '', '');

        foreach ($rows as $row) {
            if ($row['booking_id'] === $booking->id) {
                return $row;
            }
        }

        $this->fail('The booking has no row in the queue.');
    }

    /** @return list<int> */
    private function unpaidTab(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $r): int => $r['booking_id'],
            app(LimoQueueRows::class)->all(LimoQueueRows::TAB_UNPAID, '', ''),
        )));
    }

    public function test_a_booking_marked_paid_with_money_still_owed_reads_partial(): void
    {
        $booking = $this->booking(100, 40, LimoBooking::PAYMENT_PAID);

        $this->assertSame(LimoBooking::PAYMENT_PARTIAL, $booking->paymentState());

        $row = $this->row($booking);
        $this->assertSame('partial', $row['payment']);
        $this->assertEqualsWithDelta(40.0, $row['received'], 0.001);
        $this->assertEqualsWithDelta(60.0, $row['balance'], 0.001);
        $this->assertContains($booking->id, $this->unpaidTab(), 'a part-paid booking is money to chase');
    }

    public function test_the_queue_loads_what_the_invoices_paid_with_the_bookings(): void
    {
        $booking = $this->booking(100, 0, LimoBooking::PAYMENT_UNPAID);
        $this->invoice($booking, 25);

        $leg = app(LimoQueueRows::class)->query('all')->firstOrFail();

        $this->assertInstanceOf(LimoBooking::class, $leg->legable);
        $this->assertArrayHasKey('invoices_paid', $leg->legable->getAttributes(), 'loaded with the page, not per row');
        $this->assertTrue($leg->legable->relationLoaded('customer'));
        $this->assertEqualsWithDelta(25.0, (float) $leg->legable->getAttribute('invoices_paid'), 0.001);
    }

    public function test_none_some_and_all_of_the_money_read_unpaid_partial_and_paid(): void
    {
        $this->assertSame(LimoBooking::PAYMENT_UNPAID, $this->booking(100, 0, LimoBooking::PAYMENT_UNPAID)->paymentState());
        $this->assertSame(LimoBooking::PAYMENT_PARTIAL, $this->booking(100, 0.5, LimoBooking::PAYMENT_UNPAID)->paymentState());
        $this->assertSame(LimoBooking::PAYMENT_PAID, $this->booking(100, 100, LimoBooking::PAYMENT_UNPAID)->paymentState());
    }

    public function test_money_taken_against_the_invoice_counts(): void
    {
        $part = $this->booking(100, 0, LimoBooking::PAYMENT_UNPAID);
        $this->invoice($part, 30);
        $full = $this->booking(80, 0, LimoBooking::PAYMENT_PAID);
        $this->invoice($full, 80);

        $this->assertSame('partial', $this->row($part)['payment']);
        $this->assertEqualsWithDelta(30.0, $this->row($part)['received'], 0.001);
        $this->assertSame('paid', $this->row($full)['payment']);
        $this->assertEqualsWithDelta(0.0, $this->row($full)['balance'], 0.001);

        $unpaid = $this->unpaidTab();
        $this->assertContains($part->id, $unpaid);
        $this->assertNotContains($full->id, $unpaid);
    }

    public function test_a_booking_marked_paid_with_nothing_recorded_stays_paid(): void
    {
        $booking = $this->booking(100, 0, LimoBooking::PAYMENT_PAID);

        $this->assertSame('paid', $this->row($booking)['payment']);
        $this->assertNotContains($booking->id, $this->unpaidTab());
    }

    public function test_the_unpaid_tab_badge_and_the_list_agree(): void
    {
        $this->booking(100, 40, LimoBooking::PAYMENT_PAID);
        $this->booking(100, 0, LimoBooking::PAYMENT_UNPAID);
        $this->booking(100, 100, LimoBooking::PAYMENT_PAID);

        $this->assertSame(2, app(LimoQueueRows::class)->unpaidCount());
        $this->assertCount(2, $this->unpaidTab());
    }

    public function test_the_whatsapp_text_says_part_paid(): void
    {
        $booking = $this->booking(100, 40, LimoBooking::PAYMENT_PAID);
        $leg = LimoLeg::query()->where('legable_id', $booking->id)->firstOrFail();

        $text = app(LimoQueueRows::class)->whatsappText($leg);

        $this->assertStringContainsString('Part paid', $text);
        $this->assertStringNotContainsString('✅', $text);
        $this->assertStringContainsString('60.000', $text);
    }

    public function test_the_booking_page_shows_partial_paid_without_a_mark_paid_button(): void
    {
        $booking = $this->booking(100, 40, LimoBooking::PAYMENT_PAID);

        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->assertSee('Partial paid')
            ->assertSeeHtml('data-payment-state="partial"')
            ->assertDontSee('Mark paid')
            ->assertDontSee('Mark unpaid');
    }

    public function test_the_bookings_list_shows_the_partial_paid_badge(): void
    {
        $this->booking(100, 40, LimoBooking::PAYMENT_PAID);

        Livewire::test(\Modules\Limousine\Livewire\Bookings::class)
            ->assertSeeHtml('data-payment-state="partial"')
            ->assertSee('Partial paid');
    }

    public function test_the_dashboard_counts_part_paid_bookings_as_owing(): void
    {
        $this->booking(100, 40, LimoBooking::PAYMENT_PAID);
        $this->booking(100, 100, LimoBooking::PAYMENT_PAID);

        $this->assertSame(1, LimoBooking::query()->owing()->count());
        Livewire::test(LimoHome::class)->assertOk();
    }
}
