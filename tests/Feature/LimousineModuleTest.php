<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Business\Features;
use App\Erp\Enums\ModuleState;
use App\Erp\Modules\ModuleManager;
use App\Erp\Settings\Setting;
use App\Erp\Settings\SettingManager;
use App\Models\Ir\IrModel;
use App\Models\Ir\IrModule;
use App\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Limousine\Http\Controllers\LimoReportExportController;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Livewire\LimoHome;
use Modules\Limousine\Livewire\QuotationForm;
use Modules\Limousine\Livewire\Reports;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoExpense;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLocation;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Models\LimoReceipt;
use Tests\TestCase;

final class LimousineModuleTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingManager::class)->flush();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function install(): void
    {
        app(ModuleManager::class)->install('limousine');
    }

    public function test_install_creates_schema_and_registers_models(): void
    {
        $this->install();

        $this->assertSame(ModuleState::Installed, IrModule::query()->where('name', 'limousine')->sole()->state);

        foreach (['limo_locations', 'limo_bookings'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "missing {$table}");
        }
        // Customers are now shared with Rent A Car: the per-app table is merged
        // into the shared store and removed.
        $this->assertTrue(Schema::hasTable('rental_customers'));
        $this->assertFalse(Schema::hasTable('limo_customers'));

        $this->assertEqualsCanonicalizing(
            ['limousine.customer', 'limousine.location', 'limousine.booking', 'limousine.quotation', 'limousine.invoice', 'limousine.receipt', 'limousine.expense'],
            IrModel::query()->where('module', 'limousine')->pluck('model')->all(),
        );
        foreach (['limo_quotations', 'limo_invoices', 'limo_receipts', 'limo_expenses'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "missing {$table}");
        }
    }

    public function test_reports_net_collected_against_expenses_and_export(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Zain']);
        LimoBooking::query()->create([
            'customer_id' => $customer->id, 'status' => LimoBooking::STATUS_COMPLETED,
            'pickup_at' => '2026-06-10 09:00:00', 'fare' => 30,
        ]);
        $invoice = LimoInvoice::query()->create(['customer_id' => $customer->id, 'issue_date' => '2026-06-10', 'total' => 30]);
        LimoReceipt::query()->create(['invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'date' => '2026-06-10', 'amount' => 30, 'method' => 'cash']);
        LimoExpense::query()->create(['date' => '2026-06-11', 'category' => 'fuel', 'amount' => 12]);

        // Summary nets collected (30) minus expenses (12) = 18.
        Livewire::test(Reports::class)
            ->set('from', '2026-06-01')
            ->set('to', '2026-06-30')
            ->assertOk()
            ->assertSee('Net')
            ->set('tab', 'customers')->assertSee('Zain');

        // CSV export streams the in-range booking (routes aren't mounted in the
        // test harness — invoke the controller directly).
        $response = (new LimoReportExportController())(
            Request::create('/x', 'GET', ['from' => '2026-06-01', 'to' => '2026-06-30']),
        );
        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();
        $this->assertStringContainsString('Zain', $csv);
    }

    public function test_quotation_converts_then_booking_invoices_and_settles(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Noor']);

        // Quotation → booking.
        $quote = LimoQuotation::query()->create([
            'customer_id' => $customer->id, 'pickup_at' => now()->addDay(), 'fare' => 25,
        ]);
        $booking = $quote->convertToBooking();
        $this->assertSame(LimoQuotation::STATUS_CONVERTED, $quote->fresh()->status);
        $this->assertSame($booking->id, $quote->fresh()->booking_id);
        $this->assertEqualsWithDelta(25.0, $booking->fare, 0.001);

        // Booking → invoice (idempotent).
        $invoice = $booking->createInvoice();
        $this->assertEqualsWithDelta(25.0, $invoice->total, 0.001);
        $this->assertSame($invoice->id, $booking->createInvoice()->id);
        $this->assertSame(LimoInvoice::STATUS_UNPAID, $invoice->status);

        // Receipt settles it and flags the booking paid.
        LimoReceipt::query()->create([
            'invoice_id' => $invoice->id, 'customer_id' => $customer->id,
            'date' => now(), 'amount' => 25, 'method' => 'cash',
        ]);
        $this->assertSame(LimoInvoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(LimoBooking::PAYMENT_PAID, $booking->fresh()->payment_status);
    }

    public function test_quotation_form_saves_multiple_lines_with_a_grand_total(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'LineCo']);

        Livewire::test(QuotationForm::class)
            ->set('customer_id', $customer->id)
            ->set('requested_by', 'Sara')
            ->set('prepared_by', 'Qassim')
            // Line 1 (seeded): 30 × 2 = 60, − 10 discount + 5 VAT = 55.
            ->set('lines.0.quote_type', 'airport')
            ->set('lines.0.rate_type', 'fixed')
            ->set('lines.0.date_from', '2026-07-05T09:00')
            ->set('lines.0.date_to', '2026-07-05T12:00')
            ->set('lines.0.units', 2)
            ->set('lines.0.vehicle', 'sedan')
            ->set('lines.0.rate', 30)
            ->set('lines.0.discount', 10)
            ->set('lines.0.vat', 5)
            // Line 2: 40 × 1 = 40 net.
            ->call('addLine')
            ->set('lines.1.quote_type', 'hourly')
            ->set('lines.1.rate_type', 'hourly')
            ->set('lines.1.date_from', '2026-07-06T09:00')
            ->set('lines.1.date_to', '2026-07-06T13:00')
            ->set('lines.1.units', 1)
            ->set('lines.1.vehicle', 'suv')
            ->set('lines.1.rate', 40)
            ->call('save')
            ->assertHasNoErrors();

        $quote = LimoQuotation::query()->with('lines')->latest('id')->first();
        $this->assertNotNull($quote);
        $this->assertCount(2, $quote->lines);
        $this->assertEqualsWithDelta(55.0, $quote->lines[0]->net_amount, 0.001);
        $this->assertEqualsWithDelta(60.0, $quote->lines[0]->line_total, 0.001);
        $this->assertEqualsWithDelta(95.0, $quote->fare, 0.001); // 55 + 40 (grand total)
        $this->assertNotNull($quote->reference);
    }

    public function test_quotation_requires_sign_off_and_a_complete_line(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'X']);

        Livewire::test(QuotationForm::class)
            ->set('customer_id', $customer->id)
            // requested_by / prepared_by blank; line left empty
            ->call('save')
            ->assertHasErrors(['requested_by', 'prepared_by', 'lines.0.quote_type', 'lines.0.rate_type', 'lines.0.vehicle', 'lines.0.date_from']);

        $this->assertSame(0, LimoQuotation::query()->count());
    }

    public function test_removing_a_line_keeps_at_least_one(): void
    {
        $this->install();

        Livewire::test(QuotationForm::class)
            ->call('addLine')
            ->assertCount('lines', 2)
            ->call('removeLine', 1)
            ->assertCount('lines', 1)
            ->call('removeLine', 0)
            ->assertCount('lines', 1); // never drops below one
    }

    public function test_booking_form_creates_and_transitions_status(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);
        $from = LimoLocation::query()->create(['name' => 'Airport']);
        $to = LimoLocation::query()->create(['name' => 'City Centre']);

        Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('pax_name', 'John Traveller')
            ->set('pickup_location_id', $from->id)
            ->set('dropoff_location_id', $to->id)
            ->set('pickup_at', '2026-07-01T14:30')
            ->set('amount', 18.5)
            ->set('rate_type', 'fixed')
            ->set('payment_method', 'cash')
            ->set('car_details', 'Lexus ES · white')
            ->set('requested_by', 'Sara')
            ->set('prepared_by', 'Ali')
            ->call('save')
            ->assertHasNoErrors();

        $booking = LimoBooking::query()->sole();
        $this->assertSame(LimoBooking::STATUS_QUEUE, $booking->status);
        $this->assertEqualsWithDelta(18.5, $booking->fare, 0.001); // net = amount − discount
        $this->assertSame('John Traveller', $booking->pax_name);
        $this->assertSame('Lexus ES · white', $booking->car_details);
        $this->assertNotNull($booking->reference);

        // Status machine: confirm → start → complete; mark paid.
        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->call('confirm')->assertSet('status', LimoBooking::STATUS_CONFIRMED)
            ->call('start')->assertSet('status', LimoBooking::STATUS_ACTIVE)
            ->call('complete')->assertSet('status', LimoBooking::STATUS_COMPLETED)
            ->call('markPaid')->assertSet('payment_status', LimoBooking::PAYMENT_PAID);
    }

    public function test_net_amount_is_the_gross_amount_minus_discount(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Yousif']);

        Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('pax_name', 'Guest')
            ->set('pickup_at', '2026-07-01T10:00')
            ->set('amount', 100)
            ->set('discount', 20)
            ->set('advance', 30)
            ->set('rate_type', 'daily')
            ->set('payment_method', 'benefitpay')
            ->set('car_details', 'Van')
            ->set('requested_by', 'A')
            ->set('prepared_by', 'B')
            ->call('save')
            ->assertHasNoErrors();

        $booking = LimoBooking::query()->sole();
        $this->assertEqualsWithDelta(80.0, $booking->netAmount(), 0.001); // 100 − 20
        $this->assertEqualsWithDelta(80.0, $booking->fare, 0.001);        // fare stores the net
        $this->assertEqualsWithDelta(50.0, $booking->balanceDue(), 0.001); // 80 − 30 advance
        $this->assertSame('benefitpay', $booking->payment_method);
    }

    public function test_the_booking_form_requires_the_key_sheet_fields(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Nasser']);

        Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('pickup_at', '2026-07-01T10:00')
            // pax_name / rate_type / car_details / requested_by / prepared_by left blank
            ->call('save')
            ->assertHasErrors(['pax_name', 'rate_type', 'car_details', 'requested_by', 'prepared_by']);
    }

    public function test_dashboard_renders_booking_kpis(): void
    {
        $this->install();
        LimoBooking::query()->create(['status' => LimoBooking::STATUS_QUEUE, 'pickup_at' => now(), 'fare' => 10]);
        LimoBooking::query()->create(['status' => LimoBooking::STATUS_COMPLETED, 'payment_status' => LimoBooking::PAYMENT_PAID, 'pickup_at' => now(), 'fare' => 20]);

        Livewire::test(LimoHome::class)
            ->assertOk()
            ->assertSee('Bookings Queue')
            ->assertSee('Completed Trips')
            ->assertSee("Today's Bookings");
    }

    public function test_app_is_gated_by_the_limousine_feature(): void
    {
        $this->seed(SettingSeeder::class);

        // A car-rental business shows Rent A Car, not Limousine…
        Setting::set('company.business_type', 'rental');
        $this->assertTrue(Features::moduleAllowed('rental'));
        $this->assertFalse(Features::moduleAllowed('limousine'));

        // …and a limousine business shows Limousine, not Rent A Car.
        Setting::set('company.business_type', 'limousine');
        $this->assertTrue(Features::moduleAllowed('limousine'));
        $this->assertFalse(Features::moduleAllowed('rental'));
    }
}
