<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Livewire\CustomerSummary;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\AccountPayment;
use Tests\TestCase;

/**
 * One customer's account on one page.
 *
 * A corporate account books over and over, and the only way to see what they
 * had asked for was to read rows off the queue — which shows trips but never
 * answers what they owe, or what they have asked for that we have not priced.
 */
final class LimoCustomerSummaryTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function company(): LimoCustomer
    {
        return LimoCustomer::query()->create([
            'name' => 'Dadabhai Travel', 'type' => LimoCustomer::TYPE_COMPANY, 'phone' => '17000000',
        ]);
    }

    /** A billed trip for this customer, optionally part-paid. */
    private function trip(LimoCustomer $customer, float $fare, float $paid = 0): LimoBooking
    {
        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'fare' => $fare, 'advance' => $paid,
        ]);
        $booking->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'from_location' => 'Airport',
            'to_location' => 'Hotel', 'start_at' => '2026-09-05 09:00:00', 'days' => 1,
            'rate' => $fare, 'rate_basis' => 'trip', 'net_amount' => $fare,
            'status' => LimoLeg::STATUS_QUEUE,
        ]);

        $invoice = $booking->syncInvoice();
        if ($paid > 0) {
            $invoice->forceFill(['amount_paid' => $paid])->save();
        }

        return $booking;
    }

    /** Age a booking's invoice, so "oldest first" has something to sort by. */
    private function dateInvoice(LimoBooking $booking, string $date): void
    {
        LimoInvoice::query()->where('booking_id', $booking->id)->update(['issue_date' => $date]);
    }

    private function balanceOf(LimoBooking $booking): float
    {
        return LimoInvoice::query()->where('booking_id', $booking->id)->firstOrFail()->balance();
    }

    public function test_the_account_adds_up_what_is_billed_received_and_owed(): void
    {
        $customer = $this->company();
        $this->trip($customer, fare: 400);
        $this->trip($customer, fare: 100, paid: 60);

        // Someone else's money must not land on this account.
        $this->trip(LimoCustomer::query()->create(['name' => 'Walk In']), fare: 999);

        Livewire::test(CustomerSummary::class, ['id' => $customer->id])
            ->assertViewHas('billed', 500.0)
            ->assertViewHas('received', 60.0)
            ->assertViewHas('outstanding', 440.0)
            ->assertViewHas('tripCount', 2);
    }

    public function test_an_overpaid_account_reads_as_settled_not_as_a_negative(): void
    {
        $customer = $this->company();
        $this->trip($customer, fare: 100, paid: 150);

        Livewire::test(CustomerSummary::class, ['id' => $customer->id])
            ->assertViewHas('outstanding', 0.0);
    }

    public function test_it_shows_what_they_asked_for_that_is_not_billed_yet(): void
    {
        $customer = $this->company();
        $open = LimoQuotation::query()->create(['customer_id' => $customer->id, 'fare' => 80]);

        $billed = LimoQuotation::query()->create(['customer_id' => $customer->id, 'fare' => 90]);
        $billed->convertToInvoice();

        $declined = LimoQuotation::query()->create([
            'customer_id' => $customer->id, 'fare' => 70, 'status' => LimoQuotation::STATUS_DECLINED,
        ]);

        // Open means still waiting on an answer: a quote already billed is not
        // outstanding, and a declined one is a price nobody agreed.
        Livewire::test(CustomerSummary::class, ['id' => $customer->id])
            ->assertSee((string) $open->reference)
            ->assertDontSee((string) $billed->reference)
            ->assertDontSee((string) $declined->reference);
    }

    public function test_the_trips_listed_are_this_customers_alone(): void
    {
        $customer = $this->company();
        $mine = $this->trip($customer, fare: 400);
        $theirs = $this->trip(LimoCustomer::query()->create(['name' => 'Walk In']), fare: 999);

        Livewire::test(CustomerSummary::class, ['id' => $customer->id])
            ->assertSee((string) $mine->legs()->firstOrFail()->reference)
            ->assertDontSee((string) $theirs->legs()->firstOrFail()->reference);
    }

    public function test_the_queue_links_a_customers_name_to_their_account(): void
    {
        $customer = $this->company();
        $this->trip($customer, fare: 400);

        // The name on the queue is how the office gets here — that is where
        // they are already looking when the question comes up.
        Livewire::test(Bookings::class)
            ->assertSeeHtml(url('/app/limousine/customer/' . $customer->id . '/summary'));
    }

    public function test_a_lump_sum_clears_the_oldest_bills_first(): void
    {
        $customer = $this->company();
        // Written newest-first on purpose: the order money is applied in must
        // come from the bills' dates, not from the order they happen to sit in.
        $newest = $this->trip($customer, fare: 400);
        $middle = $this->trip($customer, fare: 400);
        $oldest = $this->trip($customer, fare: 100);

        $this->dateInvoice($oldest, '2026-08-01');
        $this->dateInvoice($middle, '2026-08-15');
        $this->dateInvoice($newest, '2026-09-01');

        // The customer hands over 450 against 900 owed.
        Livewire::test(CustomerSummary::class, ['id' => $customer->id])
            ->call('openPay')
            ->set('payAmount', '450')
            ->call('savePay')
            ->assertHasNoErrors();

        // Oldest cleared outright, the next one dented, the newest untouched —
        // so what stays outstanding is always the most recent work.
        $this->assertEqualsWithDelta(0.0, $this->balanceOf($oldest), 0.001);
        $this->assertEqualsWithDelta(50.0, $this->balanceOf($middle), 0.001);
        $this->assertEqualsWithDelta(400.0, $this->balanceOf($newest), 0.001);
    }

    public function test_every_slice_writes_its_own_receipt_against_its_own_job(): void
    {
        $customer = $this->company();
        $first = $this->trip($customer, fare: 100);
        $second = $this->trip($customer, fare: 400);
        $this->dateInvoice($first, '2026-08-01');
        $this->dateInvoice($second, '2026-09-01');

        Livewire::test(CustomerSummary::class, ['id' => $customer->id])
            ->call('openPay')
            ->set('payAmount', '450')
            ->call('savePay')
            ->assertHasNoErrors();

        // One lump receipt naming no trip would be paper the customer cannot
        // match to anything.
        $this->assertSame(2, LimoReceipt::query()->count());
        $this->assertEqualsWithDelta(100.0, (float) LimoReceipt::query()->where('booking_id', $first->id)->sum('amount'), 0.001);
        $this->assertEqualsWithDelta(350.0, (float) LimoReceipt::query()->where('booking_id', $second->id)->sum('amount'), 0.001);
        $this->assertSame(LimoBooking::PAYMENT_PAID, $first->fresh()?->payment_status);
    }

    public function test_more_than_the_account_owes_is_refused(): void
    {
        $customer = $this->company();
        $this->trip($customer, fare: 100);

        // Money with nowhere to go would either vanish or sit as a credit
        // nobody is tracking.
        Livewire::test(CustomerSummary::class, ['id' => $customer->id])
            ->call('openPay')
            ->set('payAmount', '150')
            ->call('savePay')
            ->assertHasErrors('payAmount');

        $this->assertSame(0, LimoReceipt::query()->count());
    }

    public function test_a_bill_with_no_trip_behind_it_is_left_out_of_the_spread(): void
    {
        $customer = $this->company();
        $quote = LimoQuotation::query()->create(['customer_id' => $customer->id, 'fare' => 200]);
        $quote->convertToInvoice();

        // It is owed, and it shows in Outstanding — but a receipt belongs to a
        // job, so it cannot be settled until the trip exists.
        $this->assertCount(0, app(AccountPayment::class)->settleable($customer)->all());

        Livewire::test(CustomerSummary::class, ['id' => $customer->id])
            ->assertViewHas('outstanding', 200.0)
            ->assertDontSee(__('Receive payment'));
    }

    public function test_the_dialog_shows_where_the_money_will_land_before_it_does(): void
    {
        $customer = $this->company();
        $first = $this->trip($customer, fare: 100);
        $second = $this->trip($customer, fare: 400);
        $this->dateInvoice($first, '2026-08-01');
        $this->dateInvoice($second, '2026-09-01');

        $plan = Livewire::test(CustomerSummary::class, ['id' => $customer->id])
            ->call('openPay')
            ->set('payAmount', '450')
            ->viewData('payPlan');

        $this->assertCount(2, $plan);
        $this->assertTrue($plan[0]['settles']);
        $this->assertFalse($plan[1]['settles']);
        $this->assertEqualsWithDelta(350.0, $plan[1]['amount'], 0.001);
    }

    public function test_the_account_is_closed_to_staff_without_customer_access(): void
    {
        $customer = $this->company();

        // It carries phone numbers, contacts and what the account owes, so it
        // takes the same permission the customer list does.
        $this->actingAs(User::factory()->create());
        Livewire::test(CustomerSummary::class, ['id' => $customer->id])->assertForbidden();
    }
}
