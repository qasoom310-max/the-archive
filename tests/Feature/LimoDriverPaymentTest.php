<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoLeg;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * The assign dialog always offers an optional commission for the trip. It is
 * kept on the trip, and an amount given to a company driver makes them a
 * commission driver on their record.
 */
final class LimoDriverPaymentTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function leg(): LimoLeg
    {
        $booking = LimoBooking::query()->create([
            'customer_id' => LimoCustomer::query()->create(['name' => 'Salem Al Hajri'])->id,
            'status' => LimoBooking::STATUS_QUEUE,
        ]);

        return LimoLeg::query()->create([
            'legable_type' => LimoBooking::class, 'legable_id' => $booking->id, 'sequence' => 0,
            'status' => LimoLeg::STATUS_QUEUE, 'start_at' => now()->addDay(),
            'from_location' => 'Four Seasons', 'to_location' => 'Airport', 'rate' => 20, 'net_amount' => 20,
        ]);
    }

    private function car(): Vehicle
    {
        return Vehicle::query()->create(['name' => 'Ford Taurus', 'plate_number' => '363899', 'status' => 'available']);
    }

    private function commissionDriver(?float $usual = 15.0): LimoDriver
    {
        return LimoDriver::query()->create([
            'name' => 'Rashid', 'active' => true,
            'pay_type' => LimoDriver::PAY_TYPE_COMMISSION, 'commission_amount' => $usual,
        ]);
    }

    private function companyDriver(): LimoDriver
    {
        return LimoDriver::query()->create(['name' => 'Habib', 'active' => true, 'pay_type' => LimoDriver::PAY_TYPE_COMPANY]);
    }

    public function test_a_commission_drivers_payment_is_saved_on_the_trip(): void
    {
        $leg = $this->leg();
        $driver = $this->commissionDriver();

        Livewire::test(Bookings::class)
            ->call('openAssign', $leg->id)
            ->set('assignCar', (string) $this->car()->id)
            ->set('assignDriver', (string) $driver->id)
            ->set('assignDriverPay', '12.5')
            ->call('saveAssign')
            ->assertHasNoErrors();

        $this->assertSame(12.5, $leg->fresh()?->driver_payment);
    }

    public function test_a_commission_given_to_a_company_driver_makes_them_a_commission_driver(): void
    {
        $leg = $this->leg();
        $driver = $this->companyDriver();

        Livewire::test(Bookings::class)
            ->call('openAssign', $leg->id)
            ->set('assignCar', (string) $this->car()->id)
            ->set('assignDriver', (string) $driver->id)
            ->set('assignDriverPay', '30')
            ->call('saveAssign')
            ->assertHasNoErrors();

        $this->assertSame(30.0, $leg->fresh()?->driver_payment);
        $driver = $driver->fresh();
        $this->assertSame(LimoDriver::PAY_TYPE_COMMISSION, $driver?->pay_type);
        $this->assertSame(30.0, (float) $driver?->commission_amount);
    }

    public function test_a_company_driver_with_no_commission_stays_a_company_driver(): void
    {
        $leg = $this->leg();
        $driver = $this->companyDriver();

        Livewire::test(Bookings::class)
            ->call('openAssign', $leg->id)
            ->set('assignCar', (string) $this->car()->id)
            ->set('assignDriver', (string) $driver->id)
            ->call('saveAssign')
            ->assertHasNoErrors();

        $this->assertNull($leg->fresh()?->driver_payment);
        $this->assertSame(LimoDriver::PAY_TYPE_COMPANY, $driver->fresh()?->pay_type);
    }

    public function test_a_commission_drivers_usual_amount_is_not_changed_by_one_trip(): void
    {
        $leg = $this->leg();
        $driver = $this->commissionDriver(15.0);

        Livewire::test(Bookings::class)
            ->call('openAssign', $leg->id)
            ->set('assignCar', (string) $this->car()->id)
            ->set('assignDriver', (string) $driver->id)
            ->set('assignDriverPay', '22')
            ->call('saveAssign')
            ->assertHasNoErrors();

        $this->assertSame(22.0, $leg->fresh()?->driver_payment);
        $this->assertSame(15.0, (float) $driver->fresh()?->commission_amount);
    }
    public function test_handing_the_trip_to_a_company_driver_clears_the_old_payment(): void
    {
        $leg = $this->leg();
        $leg->forceFill(['driver_payment' => 20, 'driver_id' => $this->commissionDriver()->id])->save();

        Livewire::test(Bookings::class)
            ->call('openAssign', $leg->id)
            ->assertSet('assignDriverPay', '20')
            ->set('assignCar', (string) $this->car()->id)
            ->set('assignDriver', (string) $this->companyDriver()->id)
            ->set('assignDriverPay', '')
            ->call('saveAssign');

        $this->assertNull($leg->fresh()?->driver_payment);
    }

    public function test_a_wrong_amount_is_refused(): void
    {
        $leg = $this->leg();

        Livewire::test(Bookings::class)
            ->call('openAssign', $leg->id)
            ->set('assignCar', (string) $this->car()->id)
            ->set('assignDriver', (string) $this->commissionDriver()->id)
            ->set('assignDriverPay', 'abc')
            ->call('saveAssign')
            ->assertHasErrors('assignDriverPay');

        $this->assertNull($leg->fresh()?->driver_id);
        $this->assertNull($leg->fresh()?->driver_payment);
    }

    public function test_the_box_is_always_shown_and_knows_the_usual_amounts(): void
    {
        $leg = $this->leg();
        $commission = $this->commissionDriver(15.0);
        $this->companyDriver();

        $html = Livewire::test(Bookings::class)->call('openAssign', $leg->id)
            ->assertViewHas('commissionDrivers', [(string) $commission->id => '15'])
            ->html();

        $this->assertStringContainsString('data-driver-pay', $html);
        $this->assertStringContainsString('Commission (BD)', $html);
        $this->assertDoesNotMatchRegularExpression('/style="display: none"\s+data-driver-pay/', $html);
        $this->assertStringNotContainsString('x-show="Object.hasOwn(rates', $html);
    }
}
