<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Rental\Livewire\QuotationForm;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalQuotation;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Services\RentalQuotationPdf;
use Tests\TestCase;

/**
 * One rental quotation for several cars: "+ Add another car" under the Car
 * box, each line a car, how many of it and its rate per car, up to 100 cars
 * in all, all for the same period.
 */
final class RentalQuotationMultiCarTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    private function car(string $plate, float $daily, float $deposit = 0): Vehicle
    {
        return Vehicle::query()->create([
            'name' => 'Car ' . $plate, 'plate_no' => $plate, 'daily_rate' => $daily,
            'weekly_rate' => $daily * 6, 'monthly_rate' => $daily * 25, 'deposit' => $deposit,
        ]);
    }

    public function test_extra_cars_are_priced_and_saved_with_the_quotation(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Fleet Co', 'phone' => '39000001']);
        $a = $this->car('1001', 10, 50);
        $b = $this->car('1002', 15, 100);
        $c = $this->car('1003', 20, 0);

        $form = Livewire::test(QuotationForm::class)
            ->set('customer_id', $customer->id)
            ->set('start_date', '2026-12-01')
            ->set('end_date', '2026-12-04')
            ->set('vehicle_id', $a->id)
            ->call('addCar')
            ->set('extraCars.0.vehicle_id', $b->id)
            ->call('addCar')
            ->set('extraCars.1.vehicle_id', $c->id)
            ->assertSet('extraCars.0.rate', '15')
            ->assertSet('extraCars.1.rate', '20')
            ->assertSet('deposit', '150')
            ->assertSee(__('Add another car'));

        // Every car line has its count and rate beside it, the first one included.
        $this->assertSame(3, substr_count($form->html(), 'data-car-rate'));
        $this->assertSame(3, substr_count($form->html(), 'data-car-count'));

        $form->call('save')->assertHasNoErrors();

        $quote = RentalQuotation::query()->firstOrFail();
        $this->assertSame(3, $quote->carCount());
        $this->assertSame([$b->id, $c->id], array_column($quote->extraCars(), 'vehicle_id'));
        // (10 + 15 + 20) × 3 days.
        $this->assertEqualsWithDelta(135.0, $quote->subtotal, 0.001);
        $this->assertEqualsWithDelta(135.0, $quote->total, 0.001);

        // Opening it again brings the extra cars back.
        Livewire::test(QuotationForm::class, ['id' => $quote->id])
            ->assertSet('extraCars.0.vehicle_id', $b->id)
            ->assertSet('extraCars.1.rate', '20');
    }

    public function test_a_count_per_car_multiplies_the_price_the_deposit_and_the_cars(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Fleet Co', 'phone' => '39000001']);
        $a = $this->car('1001', 10, 50);
        $b = $this->car('1002', 15, 100);

        Livewire::test(QuotationForm::class)
            ->set('customer_id', $customer->id)
            ->set('start_date', '2026-12-01')
            ->set('end_date', '2026-12-03')
            ->set('vehicle_id', $a->id)
            ->set('quantity', '5')
            ->call('addCar')
            ->set('extraCars.0.vehicle_id', $b->id)
            ->set('extraCars.0.qty', '2')
            ->assertSet('deposit', '450')
            ->assertSee('7')
            ->call('save')
            ->assertHasNoErrors();

        $quote = RentalQuotation::query()->firstOrFail();
        $this->assertSame(5, $quote->quantity);
        $this->assertSame(7, $quote->carCount());
        // (5 × 10 + 2 × 15) × 2 days.
        $this->assertEqualsWithDelta(160.0, $quote->subtotal, 0.001);

        $lines = app(RentalQuotationPdf::class)->viewData($quote)['lines'];
        $this->assertStringStartsWith('5 × ', $lines[0]['vehicle']);
        $this->assertEqualsWithDelta(100.0, $lines[0]['amount'], 0.001);
    }

    public function test_no_more_than_a_hundred_cars_in_all(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Fleet Co', 'phone' => '39000001']);
        $a = $this->car('1001', 10);
        $b = $this->car('1002', 15);

        $form = Livewire::test(QuotationForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $a->id)
            ->set('quantity', '99')
            ->call('addCar')
            ->set('extraCars.0.vehicle_id', $b->id);

        // 99 + 1 = 100: room for no more lines.
        $form->assertDontSee(__('Add another car'))
            ->assertSee(__('Up to :max cars on one quotation.', ['max' => RentalQuotation::MAX_CARS]))
            ->call('addCar');
        $this->assertCount(1, $form->get('extraCars'));

        $form->set('extraCars.0.qty', '2')->call('save')->assertHasErrors(['quantity']);
        $this->assertSame(0, RentalQuotation::query()->count());

        $form->set('extraCars.0.qty', '1')->call('save')->assertHasNoErrors();
        $this->assertSame(100, RentalQuotation::query()->firstOrFail()->carCount());
    }

    public function test_the_same_car_twice_or_a_blank_line_is_refused(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Fleet Co', 'phone' => '39000001']);
        $a = $this->car('1001', 10);

        Livewire::test(QuotationForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $a->id)
            ->call('addCar')
            ->set('extraCars.0.vehicle_id', $a->id)
            ->call('addCar')
            ->call('save')
            ->assertHasErrors(['extraCars.0.vehicle_id', 'extraCars.1.vehicle_id']);

        $this->assertSame(0, RentalQuotation::query()->count());
    }

    public function test_removing_a_car_drops_it_and_its_deposit(): void
    {
        $a = $this->car('1001', 10, 50);
        $b = $this->car('1002', 15, 100);

        Livewire::test(QuotationForm::class)
            ->set('vehicle_id', $a->id)
            ->call('addCar')
            ->set('extraCars.0.vehicle_id', $b->id)
            ->assertSet('deposit', '150')
            ->call('removeCar', 0)
            ->assertSet('extraCars', [])
            ->assertSet('deposit', '50');
    }

    public function test_converting_makes_an_order_per_car_leaving_extra_copies_for_the_desk(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Fleet Co', 'phone' => '39000001']);
        $a = $this->car('1001', 10);

        $quote = RentalQuotation::query()->create([
            'reference' => 'QT/7002', 'customer_id' => $customer->id, 'vehicle_id' => $a->id, 'quantity' => 3,
            'start_date' => '2026-12-01', 'end_date' => '2026-12-02', 'rate_type' => 'daily', 'rate' => 10,
        ]);
        $quote->recalcTotals();
        $quote->save();

        $quote->fresh()->convertToOrder();

        $orders = RentalOrder::query()->orderBy('id')->get();
        $this->assertCount(3, $orders);
        $this->assertSame($a->id, $orders[0]->vehicle_id);
        $this->assertNull($orders[1]->vehicle_id);
        $this->assertNull($orders[2]->vehicle_id);
        $this->assertStringContainsString('3', (string) $orders[2]->notes);
    }

    public function test_the_pdf_has_a_line_per_car_and_converting_makes_an_order_per_car(): void
    {
        $customer = RentalCustomer::query()->create(['name' => 'Fleet Co', 'phone' => '39000001']);
        $a = $this->car('1001', 10);
        $b = $this->car('1002', 15);

        $quote = RentalQuotation::query()->create([
            'reference' => 'QT/7001', 'customer_id' => $customer->id, 'vehicle_id' => $a->id,
            'start_date' => '2026-12-01', 'end_date' => '2026-12-03', 'rate_type' => 'daily',
            'rate' => 10, 'extra_cars' => [['vehicle_id' => $b->id, 'rate' => 15]], 'discount' => 5, 'deposit' => 40,
        ]);
        $quote->recalcTotals();
        $quote->save();

        $data = app(RentalQuotationPdf::class)->viewData($quote->fresh());
        $this->assertCount(2, $data['lines']);
        $this->assertStringContainsString('1002', $data['lines'][1]['vehicle']);
        $this->assertEqualsWithDelta(30.0, $data['lines'][1]['amount'], 0.001);
        $this->assertEqualsWithDelta(50.0, $data['subtotal'], 0.001);

        $first = $quote->fresh()->convertToOrder();

        $orders = RentalOrder::query()->orderBy('id')->get();
        $this->assertCount(2, $orders);
        $this->assertSame($first->id, $orders[0]->id);
        $this->assertSame($a->id, $orders[0]->vehicle_id);
        $this->assertEqualsWithDelta(5.0, (float) $orders[0]->discount, 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $orders[0]->deposit, 0.001);
        $this->assertSame($b->id, $orders[1]->vehicle_id);
        $this->assertEqualsWithDelta(15.0, (float) $orders[1]->rate, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $orders[1]->discount, 0.001);
        $this->assertStringContainsString('QT/7001', (string) $orders[1]->notes);

        // Converting again makes nothing new.
        $quote->fresh()->convertToOrder();
        $this->assertSame(2, RentalOrder::query()->count());
    }
}
