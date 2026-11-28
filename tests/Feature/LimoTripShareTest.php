<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\BookingPayments;
use Modules\Limousine\Services\LimoQueueRows;
use Tests\TestCase;

/**
 * Two trips booked together each show their OWN part of the booking's money,
 * not the whole booking's balance repeated on every row.
 */
final class LimoTripShareTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    /** @param list<float> $prices */
    private function booking(array $prices, string $type = 'individual', array $extra = []): LimoBooking
    {
        $customer = LimoCustomer::query()->create(['name' => 'Travel Gate', 'type' => $type, 'phone' => '+97333000000']);
        $booking = LimoBooking::query()->create(array_merge([
            'customer_id' => $customer->id, 'status' => LimoBooking::STATUS_QUEUE,
            'payment_status' => LimoBooking::PAYMENT_UNPAID,
        ], $extra));
        foreach ($prices as $i => $price) {
            $booking->legs()->create([
                'sequence' => $i, 'service_type' => 'transfer', 'status' => LimoLeg::STATUS_QUEUE,
                'from_location' => 'Bahrain Airport', 'to_location' => 'Dammam', 'start_at' => now()->addDay(),
                'days' => 1, 'rate' => $price, 'rate_basis' => 'trip', 'line_total' => $price, 'net_amount' => $price,
            ]);
        }
        $booking->recalcTotal();
        $booking->save();

        return $booking->refresh();
    }

    /** @return array<int, array<string, mixed>> keyed by leg id */
    private function rows(LimoBooking $booking): array
    {
        $out = [];
        foreach (app(LimoQueueRows::class)->all('all', '', '') as $row) {
            if ($row['booking_id'] === $booking->id) {
                $out[$row['leg_id']] = $row;
            }
        }
        ksort($out);

        return $out;
    }

    public function test_two_unpaid_trips_each_owe_their_own_price(): void
    {
        $booking = $this->booking([45.0, 45.0]);

        $rows = array_values($this->rows($booking));

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertEqualsWithDelta(45.0, $row['balance'], 0.001);
            $this->assertEqualsWithDelta(0.0, $row['received'], 0.001);
            $this->assertEqualsWithDelta(90.0, $row['booking_balance'], 0.001);
            $this->assertSame(LimoBooking::PAYMENT_UNPAID, $row['payment']);
        }
    }

    public function test_money_pays_the_earliest_trip_first(): void
    {
        $booking = $this->booking([45.0, 45.0]);
        app(BookingPayments::class)->receive($booking, 60.0, 'cash');

        [$first, $second] = array_values($this->rows($booking->refresh()));

        $this->assertEqualsWithDelta(45.0, $first['received'], 0.001);
        $this->assertEqualsWithDelta(0.0, $first['balance'], 0.001);
        $this->assertSame(LimoBooking::PAYMENT_PAID, $first['payment']);
        $this->assertEqualsWithDelta(15.0, $second['received'], 0.001);
        $this->assertEqualsWithDelta(30.0, $second['balance'], 0.001);
        $this->assertSame(LimoBooking::PAYMENT_PARTIAL, $second['payment']);
        $this->assertEqualsWithDelta($booking->amountOwed(), $first['balance'] + $second['balance'], 0.001);
    }

    public function test_the_copied_message_asks_the_driver_for_this_trip_only(): void
    {
        $booking = $this->booking([45.0, 45.0]);
        $leg = $booking->legs()->orderBy('sequence')->get()->last();
        $this->assertNotNull($leg);

        $text = app(LimoQueueRows::class)->whatsappText($leg);

        $this->assertStringContainsString('Balance 45.000 BD', $text);
        $this->assertStringNotContainsString('90.000', $text);
    }

    public function test_meet_and_greet_sits_on_the_first_trip_and_the_parts_add_up(): void
    {
        $booking = $this->booking([30.0, 20.0], 'individual', ['meet_greet' => true, 'meet_greet_price' => 5]);
        $booking->recalcTotal();
        $booking->save();

        $shares = array_values($booking->refresh()->tripShares());

        $this->assertEqualsWithDelta(35.0, $shares[0]['owed'], 0.001);
        $this->assertEqualsWithDelta(20.0, $shares[1]['owed'], 0.001);
        $this->assertEqualsWithDelta(55.0, $booking->amountOwed(), 0.001);
    }

    public function test_a_cancelled_trip_that_is_not_charged_owes_nothing(): void
    {
        $booking = $this->booking([45.0, 45.0]);
        $leg = $booking->legs()->orderBy('sequence')->get()->last();
        $this->assertNotNull($leg);
        $leg->forceFill(['status' => LimoLeg::STATUS_CANCELLED, 'refund_outcome' => LimoLeg::REFUND_NONE])->saveQuietly();
        $booking->recalcTotal();
        $booking->save();

        $shares = $booking->refresh()->tripShares();

        $this->assertEqualsWithDelta(0.0, $shares[(int) $leg->id]['owed'], 0.001);
        $this->assertEqualsWithDelta(45.0, array_sum(array_column($shares, 'owed')), 0.001);
    }

    public function test_a_booking_marked_paid_reads_paid_on_every_trip(): void
    {
        $booking = $this->booking([45.0, 45.0], 'individual', ['payment_status' => LimoBooking::PAYMENT_PAID]);
        LimoBooking::query()->whereKey($booking->id)->update(['payment_status' => LimoBooking::PAYMENT_PAID]);

        foreach ($this->rows($booking->refresh()) as $row) {
            $this->assertSame(LimoBooking::PAYMENT_PAID, $row['payment']);
        }
    }
}
