<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Rental\Livewire\OrderForm;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * The expanded rental order ("hire contract"): VAT, delivery, advance/balance
 * money flow, the vehicle read-out pre-fills, customer phone pre-fill, payment
 * type, and a handover photo.
 */
final class RentalOrderContractTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    public function test_recalc_totals_applies_discount_vat_delivery_and_balance(): void
    {
        $order = new RentalOrder();
        $order->start_date = Carbon::parse('2026-06-25');
        $order->end_date = Carbon::parse('2026-06-30');
        $order->rate_type = 'daily';
        $order->rate = 10;          // 5 days × 10 = 50 amount
        $order->discount = 5;        // amount net of discount = 45
        $order->vat_rate = 10;
        $order->delivery = true;     // flat drop-off fee 5 (not taxed)
        $order->advance_amount = 20;
        $order->recalcTotals();

        $this->assertSame(5, $order->days);
        $this->assertSame(50.0, $order->subtotal);
        $this->assertSame(5.0, $order->delivery_charges); // fixed fee, derived from the flag
        // VAT on the rental only: (50 − 5) × 10% = 4.5; delivery added after VAT.
        $this->assertSame(4.5, $order->vat_amount);
        $this->assertSame(54.5, $order->total);          // 45 + 4.5 + 5
        $this->assertSame(34.5, $order->balance);        // 54.5 − 20
    }

    public function test_the_delivery_charge_is_not_taxed(): void
    {
        // RO/01677: 2 days × 30 with a 10 BD delivery. VAT is 6 (on the 60), not 7.
        $order = new RentalOrder();
        $order->start_date = Carbon::parse('2026-11-05');
        $order->end_date = Carbon::parse('2026-11-07');
        $order->rate_type = 'daily';
        $order->rate = 30;
        $order->vat_rate = 10;
        $order->delivery = true;
        $order->pickup = true;
        $order->recalcTotals();

        $this->assertSame(60.0, $order->subtotal);
        $this->assertSame(10.0, $order->delivery_charges);
        $this->assertSame(6.0, $order->vat_amount);
        $this->assertSame(76.0, $order->total);
    }

    public function test_open_unpaid_orders_priced_the_old_way_lose_the_vat_on_delivery(): void
    {
        $make = function (string $ref, string $state, float $advance, float $vat) {
            $order = new RentalOrder();
            $order->forceFill([
                'reference' => $ref, 'state' => $state, 'rate_type' => 'daily', 'rate' => 30,
                'start_date' => '2026-11-05', 'end_date' => '2026-11-07', 'subtotal' => 60, 'discount' => 0,
                'extra_charge' => 0, 'delivery_charges' => 10, 'vat_rate' => 10, 'vat_amount' => $vat,
                'total' => 70 + $vat, 'advance_amount' => $advance, 'balance' => 70 + $vat - $advance,
            ])->saveQuietly();

            return $order;
        };
        $open = $make('RO/01677', RentalOrder::STATE_DRAFT, 0, 7);
        $paid = $make('RO/01678', RentalOrder::STATE_ACTIVE, 20, 7);       // money taken: left alone
        $closed = $make('RO/01679', RentalOrder::STATE_CLOSED, 0, 7);      // history: left alone
        $imported = $make('RA1800', RentalOrder::STATE_ACTIVE, 0, 6.5);    // not the old formula: left alone

        (require base_path('Modules/Rental/database/migrations/2026_11_03_900061_untax_delivery_on_open_orders.php'))->up();

        $open->refresh();
        $this->assertSame(6.0, $open->vat_amount);
        $this->assertSame(76.0, $open->total);
        $this->assertSame(76.0, $open->balance);
        $this->assertSame(7.0, $paid->refresh()->vat_amount);
        $this->assertSame(7.0, $closed->refresh()->vat_amount);
        $this->assertSame(6.5, $imported->refresh()->vat_amount);
    }

    public function test_drop_off_and_pick_up_are_each_five_bhd(): void
    {
        $order = new RentalOrder();
        $order->rate_type = 'daily';
        $order->rate = 10;
        $order->start_date = Carbon::parse('2026-06-25');
        $order->end_date = Carbon::parse('2026-06-26'); // 1 day
        $order->delivery = true;  // drop-off  +5
        $order->pickup = true;    // pick-up    +5
        $order->recalcTotals();

        $this->assertSame(10.0, $order->delivery_charges); // 5 + 5
    }

    public function test_hired_time_is_required(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Sara']);
        $vehicle = Vehicle::query()->create(['name' => 'Yaris', 'daily_rate' => 10]);

        Livewire::test(OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)
            ->set('start_date', '2026-07-01')
            ->set('end_date', '2026-07-03')
            // no hired_time
            ->call('save')
            ->assertHasErrors(['hired_time']);

        $this->assertSame(0, RentalOrder::query()->count());
    }

    public function test_saving_an_order_persists_the_contract_fields(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Ahmed', 'phone' => '39000010']);
        $vehicle = Vehicle::query()->create(['name' => 'Yaris', 'daily_rate' => 10, 'deposit' => 50, 'odometer' => 42000]);

        Livewire::test(OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id) // auto-fills rate 10
            ->set('start_date', '2026-07-01')
            ->set('end_date', '2026-07-06') // 5 days → 50 amount
            ->set('additional_driver', 'Sami')
            ->set('additional_driver_license', 'DL-77')
            ->set('hired_time', '14:30')
            ->set('delivery', true)
            ->set('delivery_location', 'Juffair, Block 338')
            ->set('advance_amount', '20')
            ->set('payment_type', 'benefitpay')
            ->call('save')
            ->assertHasNoErrors();

        $order = RentalOrder::query()->firstOrFail();
        $this->assertSame('Sami', $order->additional_driver);
        $this->assertSame('DL-77', $order->additional_driver_license);
        $this->assertSame('14:30', $order->hired_time);
        $this->assertSame('benefitpay', $order->payment_type);
        $this->assertTrue($order->delivery);
        $this->assertSame('Juffair, Block 338', $order->delivery_location);
        $this->assertSame(5.0, $order->delivery_charges); // fixed flat fee
        $this->assertSame(20.0, $order->advance_amount);
        // VAT auto-applied at 10%, balance computed.
        $this->assertSame(10.0, $order->vat_rate);
        $this->assertGreaterThan(0.0, $order->balance);
    }

    public function test_delivery_location_is_required_when_delivery_is_on(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Dana']);
        $vehicle = Vehicle::query()->create(['name' => 'Civic', 'daily_rate' => 10]);

        // Delivery on but no location → blocked.
        Livewire::test(OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)
            ->set('delivery', true)
            ->set('delivery_location', '')
            ->call('save')
            ->assertHasErrors(['delivery_location']);

        $this->assertSame(0, RentalOrder::query()->count());

        // Delivery off → the (hidden) location isn't required.
        Livewire::test(OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)
            ->set('delivery', false)
            ->set('hired_time', '10:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(RentalOrder::query()->firstOrFail()->delivery_location);
    }

    public function test_selecting_a_vehicle_prefills_mileage_and_rate(): void
    {
        $vehicle = Vehicle::query()->create(['name' => 'Sunny', 'daily_rate' => 8, 'deposit' => 40, 'odometer' => 91000]);

        Livewire::test(OrderForm::class)
            ->set('vehicle_id', $vehicle->id)
            ->assertSet('pickup_mileage', '91000')
            ->assertSet('rate', '8')
            ->assertViewHas('selectedVehicle', fn ($v): bool => $v !== null && (int) $v->id === $vehicle->id);
    }

    public function test_selecting_a_customer_prefills_the_phone(): void
    {
        $layla = RentalCustomer::query()->create(['name' => 'Layla', 'phone' => '39000011']);
        $omar = RentalCustomer::query()->create(['name' => 'Omar', 'phone' => '39000022']);

        Livewire::test(OrderForm::class)
            ->set('customer_id', $layla->id)
            ->assertSet('phone', '39000011')
            // Switching customers refreshes the phone.
            ->set('customer_id', $omar->id)
            ->assertSet('phone', '39000022');
    }

    public function test_inline_new_customer_prefills_its_phone_on_the_order(): void
    {
        Livewire::test(OrderForm::class)
            ->call('openCustomerModal')
            ->set('newCustomer.name', 'Hind')
            ->set('newCustomer.phone', '39000033')
            ->call('saveCustomer')
            ->assertSet('phone', '39000033');
    }

    public function test_cpr_and_licence_image_paths_are_stored_on_the_order(): void
    {
        // The image is uploaded by the direct controller (covered separately);
        // the form just binds and persists the stored path.
        $customer = RentalCustomer::query()->create(['name' => 'Noor']);
        $vehicle = Vehicle::query()->create(['name' => 'Civic', 'daily_rate' => 12]);

        Livewire::test(OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)
            ->set('cprImagePath', 'rental_orders/cpr.jpg')
            ->set('licenseImagePath', 'rental_orders/licence.jpg')
            ->set('hired_time', '10:00')
            ->call('save')
            ->assertHasNoErrors();

        $order = RentalOrder::query()->firstOrFail();
        $this->assertSame('rental_orders/cpr.jpg', $order->cpr_image_path);
        $this->assertSame('rental_orders/licence.jpg', $order->license_image_path);
    }
}
