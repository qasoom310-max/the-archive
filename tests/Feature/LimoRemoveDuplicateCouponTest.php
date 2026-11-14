<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCoupon;
use Modules\Limousine\Models\LimoCouponRedemption;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Tests\TestCase;

/** The one-off removal of MR.DBOUK MOHAMMED's duplicate coupon. */
final class LimoRemoveDuplicateCouponTest extends TestCase
{
    use DatabaseMigrations;

    private const MIGRATION = 'Modules/Limousine/database/migrations/2026_11_14_950056_remove_duplicate_dbouk_coupon.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    /** @return array{0: LimoCoupon, 1: LimoCoupon, 2: LimoLeg, 3: LimoBooking} */
    private function scenario(string $name = 'MR.DBOUK MOHAMMED'): array
    {
        $customer = LimoCustomer::query()->create(['name' => $name]);
        $booking = LimoBooking::query()->create([
            'reference' => 'BK/9001', 'customer_id' => $customer->id, 'status' => LimoBooking::STATUS_CANCELLED,
            'advance' => 48, 'payment_status' => LimoBooking::PAYMENT_PAID,
        ]);
        $legs = [];
        foreach (['26159', '26262'] as $i => $ref) {
            $legs[] = LimoLeg::query()->create([
                'legable_type' => LimoBooking::class, 'legable_id' => $booking->id, 'sequence' => $i,
                'reference' => $ref, 'status' => LimoLeg::STATUS_CANCELLED, 'from_location' => 'Airport',
                'rate' => 48, 'net_amount' => 48,
                'refund_outcome' => LimoLeg::REFUND_COUPON, 'refund_amount' => 48,
            ]);
        }
        $booking->recalcTotal();
        $booking->save();

        $coupons = [];
        foreach ([['CPN-0003', $legs[0]], ['CPN-0004', $legs[1]]] as [$code, $leg]) {
            $coupons[] = LimoCoupon::query()->create([
                'code' => $code, 'limo_booking_id' => $booking->id, 'limo_leg_id' => $leg->id,
                'leg_reference' => $leg->reference, 'customer_id' => $customer->id, 'amount' => 48,
                'expires_at' => now()->addYear(),
            ]);
        }

        return [$coupons[0], $coupons[1], $legs[1], $booking];
    }

    private function runFix(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    public function test_the_second_coupon_goes_and_the_first_stays(): void
    {
        [$keep, $drop, $leg, $booking] = $this->scenario();
        $this->assertEqualsWithDelta(96.0, (float) $booking->fare, 0.001);

        $this->runFix();

        $this->assertNotNull(LimoCoupon::query()->find($keep->id));
        $this->assertNull(LimoCoupon::query()->find($drop->id));
        $this->assertSame(LimoLeg::REFUND_NONE, $leg->fresh()?->refund_outcome);
        // One payment, one trip still on the bill: nothing owed, nothing over.
        $this->assertEqualsWithDelta(48.0, (float) $booking->fresh()?->fare, 0.001);
    }

    public function test_a_spent_coupon_or_another_customer_is_left_alone(): void
    {
        [, $drop] = $this->scenario('Someone Else');
        $this->runFix();
        $this->assertNotNull(LimoCoupon::query()->find($drop->id));

        LimoCoupon::query()->delete();
        LimoLeg::query()->delete();
        LimoBooking::query()->delete();

        [, $drop2, , $booking] = $this->scenario();
        LimoCouponRedemption::query()->create([
            'limo_coupon_id' => $drop2->id, 'redeemable_type' => LimoBooking::class,
            'redeemable_id' => $booking->id, 'amount' => 10,
        ]);
        $this->runFix();
        $this->assertNotNull(LimoCoupon::query()->find($drop2->id));
    }
}
