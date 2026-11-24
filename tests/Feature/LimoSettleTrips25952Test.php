<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoReceipt;
use Tests\TestCase;

/**
 * The manager accepted 680 for the booking of trips 25952 + 25953 (billed 820):
 * the data step gives 140 off trip 25953 so the booking reads paid in full.
 */
final class LimoSettleTrips25952Test extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function runStep(): void
    {
        $migration = require base_path('Modules/Limousine/database/migrations/2026_11_24_950074_settle_trips_25952_25953_at_680.php');
        $migration->up();
    }

    private function booking(float $secondPrice = 540.0): LimoBooking
    {
        $customer = LimoCustomer::query()->create(['name' => 'Lily']);
        $booking = LimoBooking::query()->create(['customer_id' => $customer->id, 'status' => LimoBooking::STATUS_QUEUE]);
        foreach ([['25952', 280.0], ['25953', $secondPrice]] as $i => [$ref, $price]) {
            $leg = $booking->legs()->create([
                'sequence' => $i, 'service_type' => 'transfer', 'from_location' => 'Khobar', 'to_location' => 'Bahrain Airport',
                'start_at' => '2026-10-26 21:00:00', 'days' => 1, 'rate' => $price, 'rate_basis' => 'trip',
                'line_total' => $price, 'net_amount' => $price,
            ]);
            LimoLeg::query()->whereKey($leg->id)->update(['reference' => $ref]);
        }
        $booking->recalcTotal();
        $booking->advance = 425.0;
        $booking->save();

        $invoice = $booking->syncInvoice();
        foreach ([285.0, 395.0] as $amount) {
            LimoReceipt::query()->create([
                'booking_id' => $booking->id, 'invoice_id' => $invoice->id, 'customer_id' => $customer->id,
                'date' => now(), 'amount' => $amount, 'method' => 'benefit',
            ]);
        }
        $invoice->refresh()->recomputePaid();

        return $booking->refresh();
    }

    public function test_the_booking_is_settled_at_680_with_a_discount_not_a_payment(): void
    {
        $booking = $this->booking();
        $this->assertSame(LimoBooking::PAYMENT_PARTIAL, $booking->paymentState());

        $this->runStep();

        $booking->refresh();
        $leg = $booking->legs()->where('reference', '25953')->firstOrFail();
        $this->assertEqualsWithDelta(400.0, (float) $leg->net_amount, 0.001);
        $this->assertEqualsWithDelta(140.0, (float) $leg->discount, 0.001);
        $this->assertEqualsWithDelta(680.0, (float) $booking->fare, 0.001);
        $this->assertEqualsWithDelta(0.0, $booking->amountOwed(), 0.001);
        $this->assertSame(LimoBooking::PAYMENT_PAID, $booking->paymentState());
        $this->assertSame(LimoBooking::PAYMENT_PAID, $booking->payment_status);
        // No money was invented.
        $this->assertSame(2, LimoReceipt::query()->count());
        $invoice = $booking->invoices()->firstOrFail();
        $this->assertEqualsWithDelta(680.0, (float) $invoice->total, 0.001);
        $this->assertEqualsWithDelta(0.0, $invoice->balance(), 0.001);
    }

    public function test_a_booking_that_no_longer_matches_is_left_alone(): void
    {
        $booking = $this->booking(500.0);

        $this->runStep();

        $this->assertEqualsWithDelta(780.0, (float) $booking->refresh()->fare, 0.001);
    }
}
