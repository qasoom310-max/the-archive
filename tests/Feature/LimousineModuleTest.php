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
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Livewire\LimoHome;
use Modules\Limousine\Livewire\QuotationForm;
use Modules\Limousine\Livewire\Reports;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoExpense;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoLocation;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Rental\Models\Vehicle;
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

    /** An available car in the shared Rent A Car fleet (valid papers). */
    private function availableCar(string $name = 'Sedan A', bool $outside = false): Vehicle
    {
        return Vehicle::query()->create([
            'name' => $name, 'daily_rate' => 10, 'active' => true, 'is_outside' => $outside,
            'status' => Vehicle::STATUS_AVAILABLE,
            'registration_expiry' => now()->addYear(),
            'insurance_expiry' => now()->addYear(),
        ]);
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

    public function test_quotation_saves_transfer_and_chauffeur_legs_with_a_grand_total(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'LineCo']);
        $carA = $this->availableCar('Sedan A');
        $carB = $this->availableCar('SUV B', outside: true);

        Livewire::test(QuotationForm::class)
            ->set('customer_id', $customer->id)
            ->set('requested_by', 'Sara')
            ->set('prepared_by', 'Qassim')
            // Leg 1 (seeded) — transfer, flat 45.
            ->set('legs.0.service_type', 'transfer')
            ->set('legs.0.from_location', 'Bahrain Airport')
            ->set('legs.0.to_location', 'Manama')
            ->set('legs.0.start_at', '2026-07-05T09:00')
            ->set('legs.0.car_id', $carA->id)
            ->set('legs.0.rate', 45)
            ->set('legs.0.rate_basis', 'trip')
            // Leg 2 — chauffeur 8h/day × 4 days at 10/hr = 320.
            ->call('addLeg')
            ->set('legs.1.service_type', 'chauffeur')
            ->set('legs.1.from_location', 'Manama')
            ->set('legs.1.start_at', '2026-07-06T09:00')
            ->set('legs.1.hours', 8)
            ->set('legs.1.days', 4)
            ->set('legs.1.car_id', $carB->id)
            ->set('legs.1.rate', 10)
            ->set('legs.1.rate_basis', 'hour')
            ->call('save')
            ->assertHasNoErrors();

        $quote = LimoQuotation::query()->with('legs')->latest('id')->first();
        $this->assertNotNull($quote);
        $this->assertCount(2, $quote->legs);
        $this->assertEqualsWithDelta(45.0, $quote->legs[0]->net_amount, 0.001);   // flat transfer
        $this->assertSame($carA->id, $quote->legs[0]->car_id);
        $this->assertStringContainsString('Sedan A', (string) $quote->legs[0]->vehicle); // label snapshot
        $this->assertEqualsWithDelta(320.0, $quote->legs[1]->net_amount, 0.001);  // 10 × 8 × 4
        $this->assertSame(4, $quote->legs[1]->days);
        $this->assertEqualsWithDelta(365.0, $quote->fare, 0.001);                 // grand total
        $this->assertNotNull($quote->reference);
    }

    public function test_only_available_cars_are_offered_on_a_leg(): void
    {
        $this->install();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $available = $this->availableCar('Free Car');
        $rented = $this->availableCar('Busy Car');
        $rented->update(['status' => Vehicle::STATUS_RENTED]); // in use in Rent A Car

        // A car with no paper dates entered is still offered (papers aren't required).
        $noPapers = Vehicle::query()->create([
            'name' => 'Papersless', 'daily_rate' => 10, 'active' => true,
            'status' => Vehicle::STATUS_AVAILABLE,
        ]);

        Livewire::test(QuotationForm::class)
            ->assertViewHas('carOptions', function (array $opts) use ($available, $rented, $noPapers): bool {
                $ids = array_column($opts, 'value');

                return in_array($available->id, $ids, true)
                    && in_array($noPapers->id, $ids, true)
                    && ! in_array($rented->id, $ids, true);
            });
    }

    public function test_quotation_requires_sign_off_and_a_complete_leg(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'X']);

        Livewire::test(QuotationForm::class)
            ->set('customer_id', $customer->id)
            // requested_by / prepared_by blank; leg left empty (transfer needs from/to/start).
            // The car is deliberately NOT required: you quote a job before any
            // vehicle is assigned to it.
            ->call('save')
            ->assertHasErrors(['requested_by', 'prepared_by', 'legs.0.from_location', 'legs.0.to_location', 'legs.0.start_at'])
            ->assertHasNoErrors(['legs.0.car_id']);

        $this->assertSame(0, LimoQuotation::query()->count());
    }

    public function test_a_chauffeur_leg_requires_hours_and_days(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Y']);

        Livewire::test(QuotationForm::class)
            ->set('customer_id', $customer->id)
            ->set('requested_by', 'A')->set('prepared_by', 'B')
            ->set('legs.0.service_type', 'chauffeur')
            ->set('legs.0.from_location', 'Manama')
            ->set('legs.0.start_at', '2026-07-05T09:00')
            ->set('legs.0.car_id', $this->availableCar()->id)
            ->set('legs.0.rate', 10)
            // hours left blank
            ->call('save')
            ->assertHasErrors(['legs.0.hours']);
    }

    public function test_removing_a_leg_keeps_at_least_one(): void
    {
        $this->install();

        Livewire::test(QuotationForm::class)
            ->call('addLeg')
            ->assertCount('legs', 2)
            ->call('removeLeg', 1)
            ->assertCount('legs', 1)
            ->call('removeLeg', 0)
            ->assertCount('legs', 1); // never drops below one
    }

    public function test_booking_form_creates_from_legs_and_transitions_status(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);
        $car = $this->availableCar('Lexus ES');

        Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('pax_name', 'John Traveller')
            ->set('requested_by', 'Sara')
            // prepared_by is not set here on purpose: it is stamped from the
            // signed-in user and is #[Locked], so a client-side set is refused.
            ->set('payment_method', 'cash')
            ->set('advance', 5)
            ->set('legs.0.service_type', 'transfer')
            ->set('legs.0.from_location', 'Airport')
            ->set('legs.0.to_location', 'City Centre')
            ->set('legs.0.start_at', '2026-07-01T14:30')
            ->set('legs.0.car_id', $car->id)
            ->set('legs.0.rate', 18.5)
            ->set('legs.0.rate_basis', 'trip')
            ->call('save')
            ->assertHasNoErrors();

        $booking = LimoBooking::query()->with('legs')->sole();
        $this->assertSame(LimoBooking::STATUS_QUEUE, $booking->status);
        $this->assertCount(1, $booking->legs);
        $this->assertEqualsWithDelta(18.5, $booking->fare, 0.001);       // grand total from legs
        $this->assertEqualsWithDelta(13.5, $booking->balanceDue(), 0.001); // 18.5 − 5 advance
        $this->assertSame('John Traveller', $booking->pax_name);
        $this->assertNotNull($booking->reference);

        // Status machine: confirm → start → complete; mark paid.
        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->call('confirm')->assertSet('status', LimoBooking::STATUS_CONFIRMED)
            ->call('start')->assertSet('status', LimoBooking::STATUS_ACTIVE)
            ->call('complete')->assertSet('status', LimoBooking::STATUS_COMPLETED)
            ->call('markPaid')->assertSet('payment_status', LimoBooking::PAYMENT_PAID);
    }

    public function test_a_booking_saves_without_a_car_and_the_car_is_required_only_to_start(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);
        $car = $this->availableCar('Lexus ES');

        // Taken over the phone: no car assigned yet.
        Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('requested_by', 'Sara')
            ->set('payment_method', 'cash')
            ->set('legs.0.service_type', 'transfer')
            ->set('legs.0.from_location', 'Airport')
            ->set('legs.0.to_location', 'City Centre')
            ->set('legs.0.start_at', '2026-07-01T14:30')
            ->set('legs.0.rate', 18.5)
            ->set('legs.0.rate_basis', 'trip')
            ->call('save')
            ->assertHasNoErrors();

        $booking = LimoBooking::query()->with('legs')->sole();
        $this->assertNull($booking->legs->first()?->car_id);

        // Dispatching without a car is refused...
        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->call('start')
            ->assertHasErrors(['legs.0.car_id']);
        $this->assertSame(LimoBooking::STATUS_QUEUE, $booking->fresh()?->status);

        // ...and allowed once one is chosen.
        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->set('legs.0.car_id', $car->id)
            ->call('save')
            ->call('start')
            ->assertHasNoErrors();
        $this->assertSame(LimoBooking::STATUS_ACTIVE, $booking->fresh()?->status);
    }

    public function test_taking_the_full_fare_as_advance_marks_the_booking_paid(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);
        $car = $this->availableCar('Lexus ES');

        // Enter a 25 BHD job and take 25 BHD at the counter. Nothing is owed,
        // so it must not still read "unpaid".
        Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('requested_by', 'Sara')
            ->set('payment_method', 'cash')
            ->set('advance', 25)
            ->set('legs.0.service_type', 'transfer')
            ->set('legs.0.from_location', 'Airport')
            ->set('legs.0.to_location', 'City')
            ->set('legs.0.start_at', '2026-08-27T17:00')
            ->set('legs.0.rate', 25)
            ->set('legs.0.rate_basis', 'trip')
            ->call('save')
            ->assertHasNoErrors();

        $booking = LimoBooking::query()->sole();
        $this->assertEqualsWithDelta(0.0, $booking->balanceDue(), 0.001);
        $this->assertSame(LimoBooking::PAYMENT_PAID, $booking->payment_status);
        unset($car);
    }

    public function test_a_two_leg_round_trip_paid_in_full_is_marked_paid(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Qassim']);

        // The reported case: airport → home (13) and home → airport (12), with
        // 25 taken up front. The fare is the SUM of the legs, so the advance
        // has to be compared against the recalculated total, not a leg.
        Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('requested_by', 'Sara')
            ->set('payment_method', 'cash')
            ->set('advance', 25)
            ->set('legs.0.service_type', 'transfer')
            ->set('legs.0.from_location', 'Bahrain Airport')
            ->set('legs.0.to_location', 'Home')
            ->set('legs.0.start_at', '2026-08-27T17:00')
            ->set('legs.0.rate', 13)
            ->set('legs.0.rate_basis', 'trip')
            ->call('addLeg')
            ->set('legs.1.service_type', 'transfer')
            ->set('legs.1.from_location', 'Home')
            ->set('legs.1.to_location', 'Bahrain Airport')
            ->set('legs.1.start_at', '2026-08-27T18:00')
            ->set('legs.1.rate', 12)
            ->set('legs.1.rate_basis', 'trip')
            ->call('save')
            ->assertHasNoErrors();

        $booking = LimoBooking::query()->sole();
        $this->assertEqualsWithDelta(25.0, $booking->fare, 0.001);
        $this->assertSame(LimoBooking::PAYMENT_PAID, $booking->payment_status);
    }

    public function test_existing_bookings_already_settled_are_backfilled_as_paid(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);

        // Rows as they sit on file today: written before the advance meant
        // anything, so they read "unpaid" despite nothing being owed.
        $settled = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'fare' => 25, 'advance' => 25,
            'payment_status' => LimoBooking::PAYMENT_UNPAID,
        ]);
        $deposit = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'fare' => 25, 'advance' => 10,
            'payment_status' => LimoBooking::PAYMENT_UNPAID,
        ]);
        $unpriced = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'fare' => 0, 'advance' => 0,
            'payment_status' => LimoBooking::PAYMENT_UNPAID,
        ]);

        // The migration file returns its (anonymous) migration instance.
        $migration = require __DIR__ . '/../../Modules/Limousine/database/migrations/2026_08_27_950014_backfill_paid_bookings_from_advance.php';
        $migration->up();

        $this->assertSame(LimoBooking::PAYMENT_PAID, $settled->fresh()?->payment_status);
        // A deposit is not settlement, and an unpriced booking isn't "paid".
        $this->assertSame(LimoBooking::PAYMENT_UNPAID, $deposit->fresh()?->payment_status);
        $this->assertSame(LimoBooking::PAYMENT_UNPAID, $unpriced->fresh()?->payment_status);
    }

    public function test_a_part_payment_is_still_unpaid(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);

        // A deposit is not settlement.
        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'pax_name' => 'A', 'requested_by' => 'S',
            'prepared_by' => 'P', 'fare' => 25, 'advance' => 10,
        ]);
        $booking->syncPaymentFromAdvance();

        $this->assertSame(LimoBooking::PAYMENT_UNPAID, $booking->fresh()?->payment_status);
    }

    public function test_lowering_the_advance_makes_the_booking_owe_again(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);

        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'pax_name' => 'A', 'requested_by' => 'S',
            'prepared_by' => 'P', 'fare' => 25, 'advance' => 25,
        ]);
        $booking->syncPaymentFromAdvance();
        $this->assertSame(LimoBooking::PAYMENT_PAID, $booking->fresh()?->payment_status);

        // Correcting a mistyped advance must put the balance back.
        $booking->advance = 5;
        $booking->save();
        $booking->syncPaymentFromAdvance();

        $this->assertSame(LimoBooking::PAYMENT_UNPAID, $booking->fresh()?->payment_status);
    }

    public function test_a_booking_settled_by_a_receipt_is_not_unmarked_by_the_advance(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);

        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'pax_name' => 'A', 'requested_by' => 'S',
            'prepared_by' => 'P', 'fare' => 25, 'advance' => 0,
        ]);
        $invoice = $booking->createInvoice();
        LimoReceipt::query()->create([
            'invoice_id' => $invoice->id, 'customer_id' => $customer->id,
            'date' => now(), 'amount' => 25, 'method' => 'cash',
        ]);
        $this->assertSame(LimoBooking::PAYMENT_PAID, $booking->fresh()?->payment_status);

        // That money never came through the advance field, so editing the
        // booking must not undo it.
        $booking->fresh()?->syncPaymentFromAdvance();

        $this->assertSame(LimoBooking::PAYMENT_PAID, $booking->fresh()?->payment_status);
    }

    /** A queued booking with one priced, car-assigned leg. */
    private function queueRow(): LimoBooking
    {
        $customer = LimoCustomer::query()->create(['name' => 'Sarah Almutairi']);
        $car = $this->availableCar('Suv');

        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'pax_name' => 'A', 'requested_by' => 'S',
            'prepared_by' => 'qassim', 'notes' => 'Meet at gate 3',
            'fare' => 60, 'advance' => 40, 'status' => LimoBooking::STATUS_QUEUE,
        ]);
        $booking->legs()->create([
            'sequence' => 0, 'service_type' => LimoLeg::TYPE_CHAUFFEUR,
            'from_location' => 'Bahrain Airport', 'to_location' => 'Bahrain',
            'start_at' => '2026-08-29 09:42:00', 'days' => 3, 'hours' => 8,
            'rate' => 60, 'rate_basis' => 'trip', 'net_amount' => 60,
            'car_id' => $car->id, 'vehicle' => 'Suv', 'status' => LimoLeg::STATUS_QUEUE,
        ]);

        return $booking;
    }

    public function test_the_queue_row_carries_every_column_the_office_needs(): void
    {
        $this->install();
        $this->queueRow();

        $row = app(\Modules\Limousine\Services\LimoQueueRows::class)->all('all', '', '')[0];

        $this->assertSame('Sarah Almutairi', $row['customer']);
        $this->assertSame('Bahrain Airport', $row['pickup']);
        $this->assertSame('Bahrain', $row['dropoff']);
        $this->assertSame('Suv', $row['vehicle']);
        $this->assertSame('qassim', $row['added_by']);
        $this->assertSame('Meet at gate 3', $row['comments']);
        $this->assertSame(__('Chauffeur'), $row['type']);

        // Amount is the LEG's; received and balance are the booking's, because
        // the customer settles the whole job rather than a leg of it.
        $this->assertEqualsWithDelta(60.0, $row['amount'], 0.001);
        $this->assertEqualsWithDelta(40.0, $row['received'], 0.001);
        $this->assertEqualsWithDelta(20.0, $row['balance'], 0.001);

        // A chauffeur booking holds the car for `days`, so it ends later than
        // it starts — a transfer would show the same instant for both.
        $this->assertStringContainsString('29-Aug-26', $row['from_date']);
        $this->assertStringContainsString('31-Aug-26', $row['to_date']);
    }

    public function test_the_queue_exports_render_in_every_format(): void
    {
        $this->install();
        $this->queueRow();

        // Module routes only mount on the boot AFTER install, so the controller
        // is invoked directly — the same workaround the report-export test uses.
        $controller = app(\Modules\Limousine\Http\Controllers\LimoQueueExportController::class);
        $request = Request::create('/x', 'GET', ['tab' => 'all']);

        // CSV — streamed, so the body has to be captured.
        ob_start();
        $controller->csv($request)->sendContent();
        $body = (string) ob_get_clean();
        $this->assertStringContainsString('Sarah Almutairi', $body);
        $this->assertStringContainsString('Bahrain Airport', $body);
        $this->assertStringContainsString("\xEF\xBB\xBF", $body, 'needs a BOM so Excel reads Arabic correctly');

        // Excel is a real xlsx: check the zip signature rather than the bytes.
        ob_start();
        $controller->excel($request)->sendContent();
        $xlsx = (string) ob_get_clean();
        $this->assertStringStartsWith('PK', $xlsx);

        // PDF likewise.
        $this->assertStringStartsWith('%PDF', $controller->pdf($request)->getContent() ?: '');

        // Print is an ordinary HTML page.
        $html = $controller->print($request)->render();
        $this->assertStringContainsString('Sarah Almutairi', $html);
        $this->assertStringContainsString('Suv', $html);
    }

    public function test_the_queue_search_matches_reference_customer_and_route(): void
    {
        $this->install();
        $this->queueRow(); // Sarah Almutairi · Bahrain Airport → Bahrain · Suv

        $other = LimoCustomer::query()->create(['name' => 'Someone Else']);
        $booking = LimoBooking::query()->create([
            'customer_id' => $other->id, 'pax_name' => 'Zed', 'requested_by' => 'S',
            'prepared_by' => 'P', 'status' => LimoBooking::STATUS_QUEUE,
        ]);
        $booking->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'from_location' => 'Riffa',
            'to_location' => 'Muharraq', 'start_at' => now(), 'days' => 1,
            'rate' => 5, 'rate_basis' => 'trip', 'status' => LimoLeg::STATUS_QUEUE,
        ]);

        $rows = app(\Modules\Limousine\Services\LimoQueueRows::class);

        $this->assertCount(2, $rows->all('all', '', '', ''));
        // Customer name, route and passenger all reach the same row.
        $this->assertCount(1, $rows->all('all', '', '', 'Sarah'));
        $this->assertCount(1, $rows->all('all', '', '', 'Riffa'));
        $this->assertCount(1, $rows->all('all', '', '', 'Zed'));
        $this->assertSame('Sarah Almutairi', $rows->all('all', '', '', 'Airport')[0]['customer']);
        $this->assertCount(0, $rows->all('all', '', '', 'nothing-matches-this'));
    }

    public function test_the_queue_search_does_not_widen_the_status_filter(): void
    {
        $this->install();
        $this->queueRow(); // queued

        $rows = app(\Modules\Limousine\Services\LimoQueueRows::class);

        // The ORs are grouped, so a search cannot leak a queued row into the
        // Completed tab.
        $this->assertCount(1, $rows->all('queue', '', '', 'Sarah'));
        $this->assertCount(0, $rows->all('completed', '', '', 'Sarah'));
    }

    public function test_the_queue_exports_respect_the_current_filter(): void
    {
        $this->install();
        $this->queueRow();

        // The row is queued, so a Completed filter must return nothing —
        // an export is of what the user is looking at, not the whole table.
        $rows = app(\Modules\Limousine\Services\LimoQueueRows::class);
        $this->assertCount(1, $rows->all('queue', '', ''));
        $this->assertCount(0, $rows->all('completed', '', ''));
    }

    public function test_the_queue_exports_are_read_gated(): void
    {
        $this->install();
        $this->queueRow();

        // An export is a copy of the data, so it must not be a way around the
        // permission on the screen. A staff account with no limousine grant is
        // refused, exactly as it would be on the list itself.
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $controller = app(\Modules\Limousine\Http\Controllers\LimoQueueExportController::class);
        $request = Request::create('/x', 'GET', ['tab' => 'all']);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $controller->csv($request);
    }

    public function test_each_leg_gets_its_own_running_reference(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);

        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'pax_name' => 'A', 'requested_by' => 'S',
            'prepared_by' => 'P', 'status' => LimoBooking::STATUS_QUEUE,
        ]);

        $refs = [];
        foreach ([0, 1, 2] as $i) {
            $refs[] = $booking->legs()->create([
                'sequence' => $i, 'service_type' => 'transfer', 'from_location' => 'A',
                'to_location' => 'B', 'start_at' => now(), 'days' => 1, 'rate' => 1, 'rate_basis' => 'trip',
            ])->fresh()?->reference;
        }

        // Plain running numbers from 10000, unique, and ascending across legs.
        $this->assertCount(3, array_unique($refs));
        foreach ($refs as $ref) {
            $this->assertMatchesRegularExpression('/^\d+$/', (string) $ref);
            $this->assertGreaterThanOrEqual(LimoLeg::REFERENCE_START, (int) $ref);
        }
        $this->assertSame($refs, collect($refs)->sort()->values()->all());
    }

    public function test_editing_a_booking_keeps_each_legs_reference_and_status(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);
        $car = $this->availableCar('Lexus ES');

        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'pax_name' => 'A', 'requested_by' => 'S',
            'prepared_by' => 'P', 'status' => LimoBooking::STATUS_QUEUE,
        ]);
        $leg = $booking->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'from_location' => 'Airport',
            'to_location' => 'City', 'start_at' => now(), 'days' => 1, 'rate' => 10,
            'rate_basis' => 'trip', 'car_id' => $car->id, 'vehicle' => 'Lexus ES',
            'status' => LimoLeg::STATUS_CONFIRMED,
        ]);
        $ref = $leg->fresh()?->reference;

        // Re-saving the booking used to delete and recreate every leg, which
        // would issue a new reference and reset the leg's progress.
        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->set('legs.0.rate', 25)
            ->call('save')
            ->assertHasNoErrors();

        $after = $leg->fresh();
        $this->assertNotNull($after, 'the leg row must survive an edit');
        $this->assertSame($ref, $after->reference);
        $this->assertSame(LimoLeg::STATUS_CONFIRMED, $after->status);
        $this->assertSame($car->id, $after->car_id);       // assigned from the queue, not wiped
        $this->assertEqualsWithDelta(25.0, $after->rate, 0.001);
    }

    public function test_legs_move_through_the_queue_independently(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);
        $car = $this->availableCar('Lexus ES');

        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'pax_name' => 'A', 'requested_by' => 'S',
            'prepared_by' => 'P', 'status' => LimoBooking::STATUS_QUEUE,
        ]);
        $one = $booking->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'from_location' => 'A', 'to_location' => 'B',
            'start_at' => now(), 'days' => 1, 'rate' => 1, 'rate_basis' => 'trip',
            'car_id' => $car->id, 'status' => LimoLeg::STATUS_QUEUE,
        ]);
        $two = $booking->legs()->create([
            'sequence' => 1, 'service_type' => 'transfer', 'from_location' => 'B', 'to_location' => 'C',
            'start_at' => now(), 'days' => 1, 'rate' => 1, 'rate_basis' => 'trip',
            'status' => LimoLeg::STATUS_QUEUE,
        ]);

        // Leg 1 runs and finishes; leg 2 has not been touched.
        Livewire::test(Bookings::class)
            ->call('advanceLeg', $one->id, LimoLeg::STATUS_CONFIRMED)
            ->call('advanceLeg', $one->id, LimoLeg::STATUS_ACTIVE)
            ->call('advanceLeg', $one->id, LimoLeg::STATUS_COMPLETED);

        $this->assertSame(LimoLeg::STATUS_COMPLETED, $one->fresh()?->status);
        $this->assertSame(LimoLeg::STATUS_QUEUE, $two->fresh()?->status);

        // The booking summarises its legs, so it is still queued: one leg waiting
        // means the job as a whole is not finished.
        $this->assertSame(LimoBooking::STATUS_QUEUE, $booking->fresh()?->status);

        // Finish the second and the booking completes with it.
        Livewire::test(Bookings::class)
            ->call('advanceLeg', $two->id, LimoLeg::STATUS_COMPLETED);
        $this->assertSame(LimoBooking::STATUS_COMPLETED, $booking->fresh()?->status);
    }

    public function test_a_leg_cannot_start_without_a_car(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);

        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'pax_name' => 'A', 'requested_by' => 'S',
            'prepared_by' => 'P', 'status' => LimoBooking::STATUS_QUEUE,
        ]);
        $leg = $booking->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'from_location' => 'A', 'to_location' => 'B',
            'start_at' => now(), 'days' => 1, 'rate' => 1, 'rate_basis' => 'trip',
            'car_id' => null, 'status' => LimoLeg::STATUS_CONFIRMED,
        ]);

        Livewire::test(Bookings::class)->call('advanceLeg', $leg->id, LimoLeg::STATUS_ACTIVE);

        $this->assertSame(LimoLeg::STATUS_CONFIRMED, $leg->fresh()?->status);
    }

    public function test_payment_stays_on_the_booking_and_covers_every_leg(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);

        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'pax_name' => 'A', 'requested_by' => 'S',
            'prepared_by' => 'P', 'status' => LimoBooking::STATUS_QUEUE, 'fare' => 40,
        ]);
        foreach ([0, 1] as $i) {
            $booking->legs()->create([
                'sequence' => $i, 'service_type' => 'transfer', 'from_location' => 'A', 'to_location' => 'B',
                'start_at' => now(), 'days' => 1, 'rate' => 20, 'rate_basis' => 'trip',
                'status' => LimoLeg::STATUS_QUEUE,
            ]);
        }

        // The customer settles the job, not a leg of it: one payment, and the
        // queue shows every leg of that booking as paid.
        Livewire::test(BookingForm::class, ['id' => $booking->id])->call('markPaid');

        $this->assertSame(LimoBooking::PAYMENT_PAID, $booking->fresh()?->payment_status);
        Livewire::test(Bookings::class)->assertSee(__('Paid'));
    }

    public function test_the_booking_form_never_offers_a_car(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);
        $this->availableCar('Lexus ES');

        // Asserted on the binding, not the word "Car", which "Car details" shares.
        Livewire::test(BookingForm::class)->assertDontSeeHtml('legs.0.car_id');

        // ...and still absent once the booking exists, at every status.
        foreach ([LimoBooking::STATUS_QUEUE, LimoBooking::STATUS_CONFIRMED, LimoBooking::STATUS_ACTIVE] as $status) {
            $b = LimoBooking::query()->create([
                'customer_id' => $customer->id, 'pax_name' => 'A', 'requested_by' => 'S',
                'prepared_by' => 'P', 'status' => $status,
            ]);
            Livewire::test(BookingForm::class, ['id' => $b->id])->assertDontSeeHtml('legs.0.car_id');
        }
    }

    public function test_a_car_is_assigned_from_the_bookings_queue(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);
        $car = $this->availableCar('Lexus ES');

        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'pax_name' => 'A', 'requested_by' => 'S',
            'prepared_by' => 'P', 'status' => LimoBooking::STATUS_QUEUE,
        ]);
        $leg = $booking->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'car_id' => null,
            'from_location' => 'Airport', 'to_location' => 'City', 'start_at' => now(),
            'days' => 1, 'rate' => 18.5, 'rate_basis' => 'trip',
        ]);

        // Assignment is per LEG, not per booking: legs run at different times
        // and are dispatched on their own.
        Livewire::test(Bookings::class)
            ->set('tab', 'queue')
            ->call('openAssign', $leg->id)
            ->assertSet('assigningId', $leg->id)
            ->set('assignCar', (string) $car->id)
            ->call('saveAssign')
            ->assertSet('assigningId', null);

        $saved = $leg->fresh();
        $this->assertSame($car->id, $saved?->car_id);
        // The label snapshot travels with it, so the leg still names its car
        // even if the fleet entry is renamed later.
        $this->assertNotNull($saved?->vehicle);
    }

    public function test_each_leg_of_a_booking_is_assigned_a_car_on_its_own(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);
        $carA = $this->availableCar('Lexus ES');
        $carB = $this->availableCar('GMC Yukon');

        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'pax_name' => 'A', 'requested_by' => 'S',
            'prepared_by' => 'P', 'status' => LimoBooking::STATUS_QUEUE,
        ]);
        $out = $booking->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'from_location' => 'Airport',
            'to_location' => 'Home', 'start_at' => now(), 'days' => 1, 'rate' => 13, 'rate_basis' => 'trip',
        ]);
        $back = $booking->legs()->create([
            'sequence' => 1, 'service_type' => 'transfer', 'from_location' => 'Home',
            'to_location' => 'Airport', 'start_at' => now()->addDay(), 'days' => 1, 'rate' => 12, 'rate_basis' => 'trip',
        ]);

        // Sending the outbound leg must leave the return leg alone — it runs on
        // another day and hasn't been dispatched yet.
        Livewire::test(Bookings::class)
            ->call('openAssign', $out->id)
            ->set('assignCar', (string) $carA->id)
            ->call('saveAssign');

        $this->assertSame($carA->id, $out->fresh()?->car_id);
        $this->assertNull($back->fresh()?->car_id);

        // The return leg then takes a different car of its own.
        Livewire::test(Bookings::class)
            ->call('openAssign', $back->id)
            ->set('assignCar', (string) $carB->id)
            ->call('saveAssign');

        $this->assertSame($carB->id, $back->fresh()?->car_id);
        $this->assertSame($carA->id, $out->fresh()?->car_id);
    }

    public function test_assigning_an_unknown_car_stores_nothing(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);

        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'pax_name' => 'A', 'requested_by' => 'S',
            'prepared_by' => 'P', 'status' => LimoBooking::STATUS_QUEUE,
        ]);
        $leg = $booking->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'from_location' => 'A',
            'to_location' => 'B', 'start_at' => now(), 'days' => 1, 'rate' => 1, 'rate_basis' => 'trip',
        ]);

        // The car id comes off the browser, so a made-up one must not be stored
        // as a dangling reference.
        Livewire::test(Bookings::class)
            ->call('openAssign', $leg->id)
            ->set('assignCar', '999999')
            ->call('saveAssign');

        $this->assertNull($leg->fresh()?->car_id);
    }

    public function test_prepared_by_is_stamped_from_the_signed_in_user_and_cannot_be_typed(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);
        $car = $this->availableCar('Lexus ES');
        $me = (string) auth()->user()?->name;

        Livewire::test(BookingForm::class)
            ->assertSet('prepared_by', $me)
            ->set('customer_id', $customer->id)
            ->set('requested_by', 'Sara')
            ->set('payment_method', 'cash')
            ->set('legs.0.service_type', 'transfer')
            ->set('legs.0.from_location', 'Airport')
            ->set('legs.0.to_location', 'City Centre')
            ->set('legs.0.start_at', '2026-07-01T14:30')
            ->set('legs.0.car_id', $car->id)
            ->set('legs.0.rate', 18.5)
            ->set('legs.0.rate_basis', 'trip')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($me, LimoBooking::query()->sole()->prepared_by);
    }

    public function test_editing_a_booking_keeps_the_original_preparer(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);

        // A booking raised by someone else entirely.
        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id,
            'pax_name' => 'John Traveller',
            'requested_by' => 'Sara',
            'prepared_by' => 'Original Preparer',
            'status' => LimoBooking::STATUS_QUEUE,
        ]);

        // Opening it as a different user must NOT reassign the sign-off —
        // otherwise the audit trail rewrites itself on every visit.
        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->assertSet('prepared_by', 'Original Preparer');
    }

    public function test_picking_a_customer_fills_the_passenger_block(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create([
            'name' => 'Gulf Air', 'phone' => '39000111', 'email' => 'ops@gulfair.test',
        ]);
        $other = LimoCustomer::query()->create([
            'name' => 'Batelco', 'phone' => '39000222', 'email' => 'travel@batelco.test',
        ]);

        $form = Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->assertSet('pax_name', 'Gulf Air')
            ->assertSet('pax_contact', '39000111')
            ->assertSet('email', 'ops@gulfair.test');

        // The passenger is often NOT the customer (a company books for a guest),
        // so the filled values must stay editable rather than locked.
        $form->set('pax_name', 'Visiting Director')
            ->assertSet('pax_name', 'Visiting Director');

        // Switching customer overwrites the block so the sheet always agrees
        // with the customer that is actually selected.
        $form->set('customer_id', $other->id)
            ->assertSet('pax_name', 'Batelco')
            ->assertSet('pax_contact', '39000222')
            ->assertSet('email', 'travel@batelco.test');
    }

    public function test_a_customer_created_inline_also_fills_the_passenger_block(): void
    {
        $this->install();

        Livewire::test(BookingForm::class)
            ->call('openCustomerModal')
            ->set('newCustomer.name', 'Ahmed Salman')
            ->set('newCustomer.phone', '36555777')
            ->set('newCustomer.email', 'ahmed@example.test')
            ->set('newCustomer.type', 'individual')
            ->call('saveCustomer')
            ->assertHasNoErrors()
            ->assertSet('pax_name', 'Ahmed Salman')
            ->assertSet('pax_contact', '36555777')
            ->assertSet('email', 'ahmed@example.test');
    }

    public function test_the_booking_form_requires_pax_sign_off_and_a_leg(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Nasser']);

        Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            // Picking the customer auto-fills the PAX name, so clear it again to
            // prove the field is still required when nothing stands in it.
            ->set('pax_name', '')
            // pax_name / requested_by blank; leg incomplete. Two fields are NOT
            // expected here: prepared_by is stamped from the signed-in user, and
            // car_id is only required when the trip is dispatched (see start()).
            ->call('save')
            ->assertHasErrors(['pax_name', 'requested_by', 'legs.0.from_location'])
            ->assertHasNoErrors(['legs.0.car_id']);
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
