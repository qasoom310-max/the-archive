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
        $order->discount = 5;        // taxable 45
        $order->vat_rate = 10;       // VAT 4.5
        $order->delivery = true;     // flat delivery fee 3
        $order->advance_amount = 20;
        $order->recalcTotals();

        $this->assertSame(5, $order->days);
        $this->assertSame(50.0, $order->subtotal);
        $this->assertSame(4.5, $order->vat_amount);     // (50 − 5) × 10%
        $this->assertSame(3.0, $order->delivery_charges); // fixed fee, derived from the flag
        $this->assertSame(52.5, $order->total);          // 45 + 4.5 + 3
        $this->assertSame(32.5, $order->balance);        // 52.5 − 20
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
        $this->assertSame(3.0, $order->delivery_charges); // fixed flat fee
        $this->assertSame(20.0, $order->advance_amount);
        // VAT auto-applied at 10%, balance computed.
        $this->assertSame(10.0, $order->vat_rate);
        $this->assertGreaterThan(0.0, $order->balance);
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

    public function test_cpr_and_licence_images_are_stored_on_the_order(): void
    {
        Storage::fake('public');
        $customer = RentalCustomer::query()->create(['name' => 'Noor']);
        $vehicle = Vehicle::query()->create(['name' => 'Civic', 'daily_rate' => 12]);

        Livewire::test(OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)
            ->set('cprPhoto', UploadedFile::fake()->image('cpr.jpg'))
            ->set('licensePhoto', UploadedFile::fake()->image('licence.jpg'))
            ->call('save')
            ->assertHasNoErrors();

        $order = RentalOrder::query()->firstOrFail();
        $this->assertNotNull($order->cpr_image_path);
        $this->assertNotNull($order->license_image_path);
        Storage::disk('public')->assertExists($order->cpr_image_path);
        Storage::disk('public')->assertExists($order->license_image_path);
    }
}
