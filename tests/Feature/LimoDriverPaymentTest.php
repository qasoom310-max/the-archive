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
 * Giving a trip to a COMMISSION driver records what they are paid for it.
 * A company driver is on a salary, so for them the box is not shown and
 * nothing is kept.
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

    public function test_a_company_driver_has_no_payment_kept(): void
    {
        $leg = $this->leg();

        Livewire::test(Bookings::class)
            ->call('openAssign', $leg->id)
            ->set('assignCar', (string) $this->car()->id)
            ->set('assignDriver', (string) $this->companyDriver()->id)
            ->set('assignDriverPay', '30')
            ->call('saveAssign')
            ->assertHasNoErrors();

        $this->assertNull($leg->fresh()?->driver_payment);
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

    public function test_the_box_knows_the_commission_drivers_and_their_usual_amount(): void
    {
        $leg = $this->leg();
        $commission = $this->commissionDriver(15.0);
        $this->companyDriver();

        $component = Livewire::test(Bookings::class)->call('openAssign', $leg->id);

        $component->assertSeeHtml('data-driver-pay')
            ->assertViewHas('commissionDrivers', [(string) $commission->id => '15']);

        // Nobody picked yet: the box starts hidden.
        $this->assertMatchesRegularExpression('/style="display: none"\s+data-driver-pay/', $component->html());
    }

    public function test_the_box_opens_shown_for_a_trip_already_with_a_commission_driver(): void
    {
        $leg = $this->leg();
        $leg->forceFill(['driver_id' => $this->commissionDriver()->id, 'driver_payment' => 18])->save();

        $html = Livewire::test(Bookings::class)->call('openAssign', $leg->id)->html();

        $this->assertDoesNotMatchRegularExpression('/style="display: none"\s+data-driver-pay/', $html);
    }
}
