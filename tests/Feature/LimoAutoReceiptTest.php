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
use Modules\Limousine\Models\LimoReceipt;
use Tests\TestCase;

/**
 * Money taken issues its own receipt.
 *
 * However it arrives — the whole fare at the counter, half now and half on
 * return, something handed over after the trip — the receipt is written by the
 * act of taking the money. Nobody goes to another screen for it, because a
 * receipt that depends on being remembered is one the customer sometimes never
 * gets.
 *
 * And a part payment says so: what was taken, and what is still owed.
 */
final class LimoAutoReceiptTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'name' => 'Qassim']));
        app(ModuleManager::class)->install('limousine');
    }

    private function customer(): LimoCustomer
    {
        return LimoCustomer::query()->create(['name' => 'Helen Friberg', 'phone' => '39000000']);
    }

    /** A saved booking for $fare with $advance already taken. */
    private function booking(float $fare, float $advance = 0): LimoBooking
    {
        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00001',
            'customer_id' => $this->customer()->id,
            'pickup_at' => now()->addDay(),
            'status' => LimoBooking::STATUS_QUEUE,
            'advance' => $advance,
            // The form requires these, so a fixture it will be saved through
            // has to carry them.
            'pax_name' => 'Helen Friberg',
            'requested_by' => 'Office',
            'prepared_by' => 'Qassim',
        ]);

        LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 0,
            'reference' => '10001',
            'status' => LimoLeg::STATUS_QUEUE,
            'start_at' => now()->addDay(),
            'from_location' => 'Hotel',
            'to_location' => 'Airport',
            'rate' => $fare, 'net_amount' => $fare,
        ]);

        $booking->recalcTotal();
        $booking->save();
        $booking->syncPaymentFromAdvance();

        return $booking->refresh();
    }

    /* ── Taken later: after the trip, or when the driver gets back ───────── */

    public function test_receiving_the_full_balance_issues_a_receipt(): void
    {
        $booking = $this->booking(45);

        Livewire::test(Bookings::class)
            ->call('openCollect', LimoLeg::query()->sole()->id)
            ->call('saveCollect')
            ->assertHasNoErrors();

        $receipt = LimoReceipt::query()->sole();
        $this->assertSame(45.0, $receipt->amount);
        $this->assertSame(0.0, $receipt->balance_after);
        $this->assertSame($booking->id, $receipt->booking_id);
        $this->assertSame($booking->customer_id, $receipt->customer_id);
        $this->assertTrue($receipt->auto);
        // Raised by the act, so it has its own number without anybody typing one.
        $this->assertNotSame('', (string) $receipt->reference);
        $this->assertStringContainsString('Paid in full', (string) $receipt->notes);
    }

    /** Half now: the receipt says what was taken AND what is still owed. */
    public function test_a_part_payment_receipt_states_the_balance(): void
    {
        $this->booking(45);

        Livewire::test(Bookings::class)
            ->call('openCollect', LimoLeg::query()->sole()->id)
            ->set('collectAmount', '25')
            ->call('saveCollect')
            ->assertHasNoErrors();

        $receipt = LimoReceipt::query()->sole();
        $this->assertSame(25.0, $receipt->amount);
        $this->assertSame(20.0, $receipt->balance_after);
        $this->assertStringContainsString('Balance', (string) $receipt->notes);
    }

    /** Two payments, two receipts — each recording the balance at its moment. */
    public function test_each_payment_gets_its_own_receipt(): void
    {
        $this->booking(45);
        $legId = LimoLeg::query()->sole()->id;

        foreach ([25, 20] as $paid) {
            Livewire::test(Bookings::class)
                ->call('openCollect', $legId)
                ->set('collectAmount', (string) $paid)
                ->call('saveCollect')
                ->assertHasNoErrors();
        }

        $receipts = LimoReceipt::query()->orderBy('id')->get();
        $this->assertCount(2, $receipts);
        $this->assertSame(20.0, $receipts[0]->balance_after);
        $this->assertSame(0.0, $receipts[1]->balance_after);
        // The booking is settled, and the paperwork adds up to the fare.
        $this->assertSame(45.0, round($receipts->sum('amount'), 3));
    }

    public function test_the_receipt_is_named_in_the_confirmation(): void
    {
        $this->booking(45);

        Livewire::test(Bookings::class)
            ->call('openCollect', LimoLeg::query()->sole()->id)
            ->call('saveCollect')
            ->assertSee(LimoReceipt::query()->sole()->reference);
    }

    /* ── Taken while the booking is written ──────────────────────────────── */

    private function form(LimoCustomer $customer, string $advance): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('requested_by', 'Office')
            ->set('legs.0.from_location', 'Hotel')
            ->set('legs.0.to_location', 'Airport')
            ->set('legs.0.start_at', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('legs.0.rate', '45')
            ->set('advance', $advance);
    }

    public function test_paying_in_full_when_booking_issues_a_receipt(): void
    {
        $this->form($this->customer(), '45')->call('save')->assertHasNoErrors();

        $receipt = LimoReceipt::query()->sole();
        $this->assertSame(45.0, $receipt->amount);
        $this->assertSame(0.0, $receipt->balance_after);
        $this->assertTrue($receipt->auto);
    }

    public function test_paying_half_when_booking_issues_a_half_receipt(): void
    {
        $this->form($this->customer(), '25')->call('save')->assertHasNoErrors();

        $receipt = LimoReceipt::query()->sole();
        $this->assertSame(25.0, $receipt->amount);
        // Priced from the legs, so the balance on the paper is the real one.
        $this->assertSame(20.0, $receipt->balance_after);
    }

    public function test_booking_with_nothing_paid_leaves_no_receipt(): void
    {
        $this->form($this->customer(), '0')->call('save')->assertHasNoErrors();

        $this->assertSame(0, LimoReceipt::query()->count());
    }

    /**
     * Editing a part-paid booking must not receipt the old money again — only
     * the increase is new.
     */
    public function test_editing_receipts_only_the_new_money(): void
    {
        $booking = $this->booking(45, advance: 25);

        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->set('advance', '45')
            ->call('save')
            ->assertHasNoErrors();

        $receipt = LimoReceipt::query()->sole();
        $this->assertSame(20.0, $receipt->amount);
        $this->assertSame(0.0, $receipt->balance_after);
    }

    public function test_saving_without_touching_the_money_issues_nothing(): void
    {
        $booking = $this->booking(45, advance: 25);

        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->set('pax_name', 'Helen')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, LimoReceipt::query()->count());
    }
}
