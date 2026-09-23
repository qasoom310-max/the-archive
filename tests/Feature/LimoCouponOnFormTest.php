<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCoupon;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Tests\TestCase;

/**
 * Applying a coupon while the booking is still being written.
 *
 * The office is on the phone working out what the customer owes, and the answer
 * depends on the credit — so pressing Apply has to answer it THEN, not after
 * saving. The code is checked and the amount shown against the total; the
 * coupon is only actually spent on save, because a coupon is money against a
 * bill and there is no bill until then.
 */
final class LimoCouponOnFormTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function customer(): LimoCustomer
    {
        return LimoCustomer::query()->create(['name' => 'Helen Friberg', 'phone' => '+973 1234']);
    }

    private function coupon(float $amount = 45, ?int $customerId = null): LimoCoupon
    {
        return LimoCoupon::query()->create([
            'code' => 'CPN-0001',
            'customer_id' => $customerId,
            'leg_reference' => '10000',
            'amount' => $amount,
            'expires_at' => now()->addMonths(12),
        ]);
    }

    /** A form with one 45 BD leg on it, the way the office fills it in. */
    private function form(LimoCustomer $customer): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('requested_by', 'Office')
            ->set('legs.0.from_location', 'Hotel')
            ->set('legs.0.to_location', 'Bahrain Airport')
            ->set('legs.0.start_at', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('legs.0.rate', '45')
            ->set('legs.0.car_details', 'Sedan');
    }

    /** The reported problem: Apply refused on a booking that did not exist yet. */
    public function test_apply_works_before_the_booking_is_saved(): void
    {
        $this->coupon(45);

        $this->form($this->customer())
            ->set('couponCode', 'CPN-0001')
            ->call('applyCoupon')
            ->assertHasNoErrors()
            ->assertSet('couponAccepted', 'CPN-0001');
    }

    /** And the balance says what the customer actually hands over. */
    public function test_the_credit_comes_off_the_balance_on_screen(): void
    {
        $this->coupon(20);

        $this->form($this->customer())
            ->set('couponCode', 'CPN-0001')
            ->call('applyCoupon')
            // 45 fare, 20 of credit → 25 to collect.
            ->assertViewHas('couponCredit', 20.0)
            ->assertViewHas('balance', 25.0);
    }

    /** A coupon bigger than the fare covers the fare, not more. */
    public function test_credit_never_exceeds_what_is_owed(): void
    {
        $this->coupon(100);

        $this->form($this->customer())
            ->set('couponCode', 'CPN-0001')
            ->call('applyCoupon')
            ->assertViewHas('couponCredit', 45.0)
            ->assertViewHas('balance', 0.0);
    }

    /** It follows the legs: add a trip and more of the coupon is used. */
    public function test_the_credit_follows_the_total(): void
    {
        $this->coupon(100);

        $component = $this->form($this->customer())
            ->set('couponCode', 'CPN-0001')
            ->call('applyCoupon')
            ->assertViewHas('couponCredit', 45.0);

        $component->call('addLeg')
            ->set('legs.1.from_location', 'Airport')
            ->set('legs.1.to_location', 'Hotel')
            ->set('legs.1.start_at', now()->addDays(2)->format('Y-m-d\TH:i'))
            ->set('legs.1.rate', '30')
            ->set('legs.1.car_details', 'Sedan')
            // 75 owed now, and the coupon covers all of it — no second Apply.
            ->assertViewHas('couponCredit', 75.0)
            ->assertViewHas('balance', 0.0);
    }

    /** An advance already taken is money too: credit only covers what is left. */
    public function test_credit_sits_behind_the_advance(): void
    {
        $this->coupon(100);

        $this->form($this->customer())
            ->set('advance', '20')
            ->set('couponCode', 'CPN-0001')
            ->call('applyCoupon')
            ->assertViewHas('couponCredit', 25.0)
            ->assertViewHas('balance', 0.0);
    }

    public function test_a_bad_code_says_so_and_takes_nothing_off(): void
    {
        $this->form($this->customer())
            ->set('couponCode', 'CPN-9999')
            ->call('applyCoupon')
            ->assertHasErrors('couponCode')
            ->assertSet('couponAccepted', null)
            ->assertViewHas('couponCredit', 0.0)
            ->assertViewHas('balance', 45.0);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $this->coupon(45)->forceFill(['expires_at' => now()->subDay()])->save();

        $this->form($this->customer())
            ->set('couponCode', 'CPN-0001')
            ->call('applyCoupon')
            ->assertHasErrors('couponCode')
            ->assertSet('couponAccepted', null);
    }

    /** Checking is not spending: nothing leaves the coupon until Save. */
    public function test_applying_does_not_spend_the_coupon(): void
    {
        $coupon = $this->coupon(45);

        $this->form($this->customer())
            ->set('couponCode', 'CPN-0001')
            ->call('applyCoupon');

        $this->assertSame(45.0, $coupon->fresh()?->remaining());
        $this->assertSame(0, $coupon->redemptions()->count());
    }

    public function test_saving_spends_it_and_settles_the_booking(): void
    {
        $coupon = $this->coupon(45);

        $this->form($this->customer())
            ->set('couponCode', 'CPN-0001')
            ->call('applyCoupon')
            ->call('save')
            ->assertHasNoErrors();

        $booking = LimoBooking::query()->firstOrFail();
        $this->assertSame(45.0, $booking->fare);
        $this->assertSame(45.0, $booking->advance);
        $this->assertSame(0.0, $booking->balanceDue());
        $this->assertSame(LimoBooking::PAYMENT_PAID, $booking->payment_status);

        $this->assertSame(0.0, $coupon->fresh()?->remaining());
        $this->assertSame(1, $coupon->redemptions()->count());
    }

    /** A code typed but never applied must not be spent by pressing Save. */
    public function test_an_unapplied_code_is_not_spent(): void
    {
        $coupon = $this->coupon(45);

        $this->form($this->customer())
            ->set('couponCode', 'CPN-0001')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(45.0, $coupon->fresh()?->remaining());
        $this->assertSame(45.0, LimoBooking::query()->firstOrFail()->balanceDue());
    }

    /** Arriving from the coupons page shows the credit without pressing Apply. */
    public function test_arriving_from_the_coupons_page_applies_it_already(): void
    {
        $customer = $this->customer();
        $this->coupon(45, $customer->id);

        Livewire::withQueryParams(['coupon' => 'CPN-0001', 'customer' => $customer->id])
            ->test(BookingForm::class)
            ->assertSet('couponAccepted', 'CPN-0001')
            ->assertSet('customer_id', $customer->id)
            ->set('legs.0.from_location', 'Hotel')
            ->set('legs.0.to_location', 'Airport')
            ->set('legs.0.start_at', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('legs.0.rate', '45')
            ->set('legs.0.car_details', 'Sedan')
            ->assertViewHas('couponCredit', 45.0)
            ->assertViewHas('balance', 0.0);
    }

    /** On a saved booking it still behaves — check, then save to spend. */
    public function test_it_works_on_an_existing_booking_too(): void
    {
        $coupon = $this->coupon(45);
        $customer = $this->customer();

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00001',
            'customer_id' => $customer->id,
            'pickup_at' => now()->addDay(),
            'status' => LimoBooking::STATUS_QUEUE,
            // Locked and taken from the record, so the fixture has to carry it.
            'prepared_by' => 'Office',
            'requested_by' => 'Office',
            'pax_name' => 'Helen Friberg',
        ]);

        LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 0,
            'reference' => '10001',
            'status' => LimoLeg::STATUS_QUEUE,
            'start_at' => now()->addDay(),
            'from_location' => 'Hotel',
            'vehicle_details' => 'Sedan',
            'to_location' => 'Airport',
            'rate' => 45, 'net_amount' => 45,
        ]);

        $booking->recalcTotal();
        $booking->save();

        Livewire::test(BookingForm::class, ['id' => $booking->id])
            ->set('requested_by', 'Office')
            ->set('pax_name', 'Helen Friberg')
            ->set('couponCode', 'CPN-0001')
            ->call('applyCoupon')
            ->assertViewHas('couponCredit', 45.0)
            ->assertViewHas('balance', 0.0)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0.0, $booking->fresh()?->balanceDue());
        $this->assertSame(0.0, $coupon->fresh()?->remaining());
    }
}
