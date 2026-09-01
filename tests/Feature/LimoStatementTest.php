<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Modules\Limousine\Http\Controllers\LimoStatementController;
use Modules\Limousine\Livewire\CustomerSummary;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\AccountPayment;
use Modules\Limousine\Services\BookingPayments;
use Modules\Limousine\Services\LimoStatement;
use Tests\TestCase;

/**
 * A statement of account, and the late fee that can appear on one.
 *
 * What a company asks for when it wants to check our figures against its own.
 * A list of unpaid bills cannot show that something WAS paid, when, or against
 * which receipt — which is exactly what an accounts department reconciles.
 */
final class LimoStatementTest extends TestCase
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
        return LimoCustomer::query()->create(['name' => 'Dadabhai Travel', 'type' => 'company']);
    }

    /** A billed trip dated into the past. */
    private function trip(LimoCustomer $customer, float $fare, string $date): LimoBooking
    {
        $booking = LimoBooking::query()->create(['customer_id' => $customer->id, 'fare' => $fare]);
        $invoice = $booking->syncInvoice();
        $invoice->forceFill(['issue_date' => $date])->save();

        return $booking;
    }

    public function test_the_statement_lists_charges_and_payments_with_a_running_balance(): void
    {
        $customer = $this->company();
        $june = $this->trip($customer, fare: 400, date: '2026-06-10');
        $this->trip($customer, fare: 100, date: '2026-07-05');

        app(BookingPayments::class)->receive($june, 150, 'transfer');
        LimoReceipt::query()->latest('id')->firstOrFail()->forceFill(['date' => '2026-06-20'])->save();

        $data = app(LimoStatement::class)->build($customer, '2026-06-01', '2026-07-31');

        $this->assertEqualsWithDelta(0.0, $data['opening'], 0.001);
        $this->assertCount(3, $data['lines']);
        // 400 charged, 150 paid, 100 charged — checked line by line, which is
        // the whole reason the running balance is there.
        $this->assertEqualsWithDelta(400.0, $data['lines'][0]['balance'], 0.001);
        $this->assertEqualsWithDelta(250.0, $data['lines'][1]['balance'], 0.001);
        $this->assertEqualsWithDelta(350.0, $data['closing'], 0.001);
    }

    public function test_a_payment_line_carries_the_receipt_number(): void
    {
        $customer = $this->company();
        $trip = $this->trip($customer, fare: 400, date: '2026-06-10');
        app(BookingPayments::class)->receive($trip, 150, 'cash');

        $receipt = LimoReceipt::query()->firstOrFail();
        $data = app(LimoStatement::class)->build($customer);

        // The number the customer quotes back at us when they query it.
        $payment = collect($data['lines'])->firstWhere('payment', '>', 0);
        $this->assertSame($receipt->reference, $payment['receipt']);
        $this->assertSame('Cash', $payment['method']);
    }

    public function test_a_range_starts_from_what_was_already_owed(): void
    {
        $customer = $this->company();
        $this->trip($customer, fare: 400, date: '2026-05-02');
        $this->trip($customer, fare: 100, date: '2026-06-10');

        // Without the brought-forward line a June statement would read as
        // though the account opened that morning at zero.
        $data = app(LimoStatement::class)->build($customer, '2026-06-01', '2026-06-30');

        $this->assertEqualsWithDelta(400.0, $data['opening'], 0.001);
        $this->assertCount(1, $data['lines']);
        $this->assertEqualsWithDelta(500.0, $data['closing'], 0.001);
    }

    public function test_the_statement_downloads_as_a_pdf(): void
    {
        $customer = $this->company();
        $this->trip($customer, fare: 400, date: '2026-06-10');

        // Module routes only mount on the boot AFTER install, so the controller
        // is invoked directly — the workaround the other export tests use.
        $response = (new LimoStatementController())(
            $customer->id,
            Request::create('/', 'GET', ['from' => '2026-06-01', 'to' => '2026-06-30']),
            app(LimoStatement::class),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString('statement-Dadabhai-Travel', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_a_late_fee_is_charged_to_the_account_and_shows_what_it_is_for(): void
    {
        $customer = $this->company();
        $this->trip($customer, fare: 400, date: '2026-06-10');

        Livewire::test(CustomerSummary::class, ['id' => $customer->id])
            ->set('from', '2026-06-01')
            ->set('to', '2026-07-31')
            ->call('openFee')
            // Prefilled from the period just looked at, which is what a late fee
            // is almost always charged for.
            ->assertSet('feePeriod', 'June 2026 — July 2026')
            ->set('feeAmount', '25')
            ->set('feeDate', '2026-08-01')
            ->call('saveFee')
            ->assertHasNoErrors();

        $fee = LimoInvoice::query()->whereNotNull('charge_label')->firstOrFail();
        $this->assertEqualsWithDelta(25.0, $fee->total, 0.001);
        $this->assertStringContainsString('June 2026 — July 2026', (string) $fee->charge_label);
        // It is a debt like any other, so it lands in what the account owes.
        $this->assertTrue($fee->isCharge());
    }

    public function test_the_fee_appears_on_the_statement_as_its_own_line(): void
    {
        $customer = $this->company();
        LimoInvoice::query()->create([
            'customer_id' => $customer->id, 'issue_date' => '2026-08-01',
            'subtotal' => 25, 'total' => 25, 'charge_label' => 'Late payment charge — June 2026',
        ]);

        $data = app(LimoStatement::class)->build($customer);

        // Named by what it is, not by a route it never had.
        $this->assertSame('Late payment charge — June 2026', $data['lines'][0]['description']);
        $this->assertEqualsWithDelta(25.0, $data['closing'], 0.001);
    }

    public function test_a_late_fee_can_be_paid_even_though_it_has_no_trip(): void
    {
        $customer = $this->company();
        $fee = LimoInvoice::query()->create([
            'customer_id' => $customer->id, 'issue_date' => '2026-08-01',
            'subtotal' => 25, 'total' => 25, 'charge_label' => 'Late payment charge — June 2026',
        ]);

        // The "create the trip first" rule is about a quote waiting to be
        // dispatched. A charge has no journey to wait for.
        $this->assertFalse($fee->isAwaitingTrip());

        Livewire::test(CustomerSummary::class, ['id' => $customer->id])
            ->call('openPay')
            ->assertSet('payAmount', '25')
            ->call('savePay')
            ->assertHasNoErrors();

        $this->assertSame(LimoInvoice::STATUS_PAID, $fee->fresh()?->status);
        $this->assertSame(1, LimoReceipt::query()->where('invoice_id', $fee->id)->count());
    }

    public function test_a_quotes_bill_still_waits_for_its_trip(): void
    {
        $customer = $this->company();
        $quote = \Modules\Limousine\Models\LimoQuotation::query()->create([
            'customer_id' => $customer->id, 'fare' => 30,
        ]);
        $invoice = $quote->convertToInvoice();

        $this->assertTrue($invoice->isAwaitingTrip());
        $this->assertCount(0, app(AccountPayment::class)->settleable($customer)->all());
    }

    public function test_raising_a_penalty_takes_an_admin(): void
    {
        $customer = $this->company();

        // Taking money that is owed is ordinary work. Deciding the customer
        // owes MORE than we quoted is not.
        $this->actingAs(User::factory()->create());
        $this->grantEveryone('limousine.customer');

        Livewire::test(CustomerSummary::class, ['id' => $customer->id])
            ->call('openFee')
            ->assertForbidden();

        $this->assertSame(0, LimoInvoice::query()->count());
    }
}
