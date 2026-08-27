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
            // requested_by / prepared_by blank; leg left empty (transfer needs from/to/start/car)
            ->call('save')
            ->assertHasErrors(['requested_by', 'prepared_by', 'legs.0.from_location', 'legs.0.to_location', 'legs.0.start_at', 'legs.0.car_id']);

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
            ->set('prepared_by', 'Ali')
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
            // pax_name / requested_by / prepared_by blank; leg incomplete
            ->call('save')
            ->assertHasErrors(['pax_name', 'requested_by', 'prepared_by', 'legs.0.from_location', 'legs.0.car_id']);
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
