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
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Livewire\LimoHome;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
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

        foreach (['limo_customers', 'limo_locations', 'limo_bookings'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "missing {$table}");
        }

        $this->assertEqualsCanonicalizing(
            ['limousine.customer', 'limousine.location', 'limousine.booking', 'limousine.quotation', 'limousine.invoice', 'limousine.receipt'],
            IrModel::query()->where('module', 'limousine')->pluck('model')->all(),
        );
        foreach (['limo_quotations', 'limo_invoices', 'limo_receipts'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "missing {$table}");
        }
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

    public function test_booking_form_creates_and_transitions_status(): void
    {
        $this->install();
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);
        $from = LimoLocation::query()->create(['name' => 'Airport']);
        $to = LimoLocation::query()->create(['name' => 'City Centre']);

        Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('pickup_location_id', $from->id)
            ->set('dropoff_location_id', $to->id)
            ->set('pickup_at', '2026-07-01T14:30')
            ->set('fare', 18.5)
            ->call('save')
            ->assertHasNoErrors();

        $booking = LimoBooking::query()->sole();
        $this->assertSame(LimoBooking::STATUS_QUEUE, $booking->status);
        $this->assertEqualsWithDelta(18.5, $booking->fare, 0.001);
        $this->assertNotNull($booking->reference);

        // Status machine: confirm → start → complete; mark paid.
        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->call('confirm')->assertSet('status', LimoBooking::STATUS_CONFIRMED)
            ->call('start')->assertSet('status', LimoBooking::STATUS_ACTIVE)
            ->call('complete')->assertSet('status', LimoBooking::STATUS_COMPLETED)
            ->call('markPaid')->assertSet('payment_status', LimoBooking::PAYMENT_PAID);
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
