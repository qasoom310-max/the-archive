<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Settings\Setting;
use App\Erp\Settings\SettingManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Limousine\Livewire\DriverPayments;
use Modules\Limousine\Livewire\OutsideDrivers;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoDriverPayment;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Support\DriverBalances;
use Tests\TestCase;

/**
 * Paying outside drivers: what each is owed (trips driven from the start date
 * up to now, less payments), paying in full or any amount, who asked for it,
 * and cancelling a mistaken payment without deleting it.
 */
final class LimoDriverPaymentsTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;

    private User $ali;

    private LimoDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingManager::class)->flush();
        app(ModuleManager::class)->install('limousine');
        Carbon::setTestNow(Carbon::parse('2026-11-23 12:00:00'));

        $this->owner = User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
        $this->ali = User::factory()->create(['name' => 'Ali', 'can_request_driver_payments' => true]);
        $this->actingAs($this->owner);

        $this->driver = LimoDriver::query()->create(['name' => 'Outside Sam', 'pay_type' => LimoDriver::PAY_TYPE_COMMISSION, 'commission_amount' => 20]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function trip(string $when, float $pay, string $status = LimoLeg::STATUS_COMPLETED): LimoLeg
    {
        $booking = LimoBooking::query()->create(['customer_id' => LimoCustomer::query()->create(['name' => 'C ' . uniqid()])->id, 'fare' => 50]);

        return $booking->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'status' => $status,
            'from_location' => 'A', 'to_location' => 'B', 'start_at' => $when, 'days' => 1,
            'rate' => 50, 'rate_basis' => 'trip', 'net_amount' => 50,
            'driver_id' => $this->driver->id, 'driver_payment' => $pay,
        ]);
    }

    public function test_owed_counts_trips_driven_from_the_start_date_up_to_now(): void
    {
        $this->trip('2026-10-20 09:00:00', 99);                        // before the start: paid outside the system
        $this->trip('2026-11-05 09:00:00', 20);
        $this->trip('2026-11-20 09:00:00', 30);
        $this->trip('2026-11-21 09:00:00', 40, LimoLeg::STATUS_CANCELLED);
        $this->trip('2026-11-28 09:00:00', 25, LimoLeg::STATUS_CONFIRMED); // not driven yet

        $b = app(DriverBalances::class)->of($this->driver->id);

        $this->assertSame(50.0, $b['earned']);
        $this->assertSame(50.0, $b['owed']);
        $this->assertSame(25.0, $b['ahead']);
        $this->assertSame(2, $b['trips']);

        Setting::set(DriverBalances::START_KEY, '2026-10-01');
        $this->assertSame(149.0, app(DriverBalances::class)->of($this->driver->id)['owed']);
    }

    public function test_pay_full_and_pay_part_with_who_asked_and_when(): void
    {
        $this->trip('2026-11-05 09:00:00', 60);

        Livewire::test(DriverPayments::class)
            ->call('openPay', $this->driver->id)
            ->assertSet('amount', '60')
            ->set('amount', '25')
            ->set('requestedBy', (string) $this->ali->id)
            ->set('paidOn', '2026-11-22')
            ->set('method', 'transfer')
            ->call('savePay')
            ->assertHasNoErrors()
            ->assertSet('payingDriverId', null)
            ->assertSee('DPAY/00001')
            ->assertSee('Ali');

        $payment = LimoDriverPayment::query()->firstOrFail();
        $this->assertSame(25.0, $payment->amount);
        $this->assertSame('2026-11-22', $payment->paid_on->toDateString());
        $this->assertSame($this->ali->id, $payment->requested_by_user_id);
        $this->assertSame($this->owner->id, $payment->recorded_by_user_id);
        $this->assertSame(35.0, app(DriverBalances::class)->of($this->driver->id)['owed']);

        // Pay full: the rest.
        Livewire::test(DriverPayments::class)
            ->call('openPay', $this->driver->id)
            ->set('amount', '1')
            ->call('payFull')
            ->assertSet('amount', '35')
            ->set('requestedBy', (string) $this->ali->id)
            ->call('savePay')
            ->assertHasNoErrors();

        $this->assertSame(0.0, app(DriverBalances::class)->of($this->driver->id)['owed']);
    }

    public function test_only_the_named_people_can_be_chosen_as_requested_by(): void
    {
        $this->trip('2026-11-05 09:00:00', 60);
        $someone = User::factory()->create(['name' => 'Hasan']);

        Livewire::test(DriverPayments::class)
            ->call('openPay', $this->driver->id)
            ->set('requestedBy', '')
            ->call('savePay')
            ->assertHasErrors('requestedBy')
            ->set('requestedBy', (string) $someone->id)
            ->call('savePay')
            ->assertHasErrors('requestedBy');

        $this->assertSame(0, LimoDriverPayment::query()->count());
    }

    public function test_paying_more_than_owed_needs_a_tick(): void
    {
        $this->trip('2026-11-05 09:00:00', 20);

        $page = Livewire::test(DriverPayments::class)
            ->call('openPay', $this->driver->id)
            ->set('amount', '50')
            ->set('requestedBy', (string) $this->ali->id)
            ->call('savePay')
            ->assertHasErrors('amount');
        $this->assertSame(0, LimoDriverPayment::query()->count());

        $page->set('confirmAhead', true)->call('savePay')->assertHasNoErrors();
        $this->assertSame(-30.0, app(DriverBalances::class)->of($this->driver->id)['owed']);
    }

    public function test_a_future_date_is_refused(): void
    {
        $this->trip('2026-11-05 09:00:00', 20);

        Livewire::test(DriverPayments::class)
            ->call('openPay', $this->driver->id)
            ->set('paidOn', '2026-12-01')
            ->set('requestedBy', (string) $this->ali->id)
            ->call('savePay')
            ->assertHasErrors('paidOn');
    }

    public function test_the_owner_cancels_a_payment_which_stays_on_the_list(): void
    {
        $this->trip('2026-11-05 09:00:00', 20);
        $payment = LimoDriverPayment::query()->create(['driver_id' => $this->driver->id, 'amount' => 20, 'paid_on' => '2026-11-22', 'requested_by_user_id' => $this->ali->id]);

        Livewire::test(DriverPayments::class)
            ->call('openCancel', $payment->id)
            ->call('confirmCancel')
            ->assertHasErrors('cancelReason')
            ->set('cancelReason', 'Typed twice')
            ->call('confirmCancel')
            ->assertSee('Typed twice');

        $this->assertNotNull($payment->fresh()?->cancelled_at);
        $this->assertSame(1, LimoDriverPayment::query()->count());
        $this->assertSame(20.0, app(DriverBalances::class)->of($this->driver->id)['owed']);
    }

    public function test_who_may_see_pay_and_cancel(): void
    {
        $this->grantEveryone('limousine.driver');
        $payment = LimoDriverPayment::query()->create(['driver_id' => $this->driver->id, 'amount' => 5, 'paid_on' => '2026-11-22']);

        // An accountant pays, but cannot cancel.
        $this->actingAs(User::factory()->create(['is_accountant' => true]));
        Livewire::test(DriverPayments::class)->call('openPay', $this->driver->id)->assertSet('payingDriverId', $this->driver->id);
        Livewire::test(DriverPayments::class)->call('openCancel', $payment->id)->assertForbidden();

        // An ordinary admin sees the page but cannot pay.
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Livewire::test(DriverPayments::class)->assertOk()->assertDontSeeHtml('data-pay=')->call('openPay', $this->driver->id)->assertForbidden();

        // Staff do not see it at all.
        $this->actingAs(User::factory()->create());
        Livewire::test(DriverPayments::class)->assertForbidden();
    }

    public function test_the_outside_drivers_page_shows_owed_now_and_a_pay_button(): void
    {
        $this->trip('2026-11-05 09:00:00', 40);

        Livewire::test(OutsideDrivers::class)
            ->assertSeeHtml('data-driver-payments-link')
            ->assertSeeHtml('data-owed-now="' . $this->driver->id . '"')
            ->assertSeeHtml('/app/limousine/driver-payments?pay=' . $this->driver->id);
    }
}
