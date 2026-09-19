<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\LimoQueueRows;
use Tests\TestCase;

/**
 * The trip as the office sends it: the queue's Type column, and the WhatsApp
 * message copied off a reference.
 */
final class LimoTripMessageTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    /**
     * @param array<string, mixed> $bookingAttrs
     * @param array<string, mixed> $legAttrs
     */
    private function leg(array $bookingAttrs = [], array $legAttrs = []): LimoLeg
    {
        $customer = LimoCustomer::query()->create(array_merge(
            ['name' => 'Braxtone Plus W.L.L', 'phone' => '+973 39795544'],
            $bookingAttrs['customer'] ?? [],
        ));
        unset($bookingAttrs['customer']);

        $booking = LimoBooking::query()->create(array_merge([
            'reference' => 'BK/00001',
            'customer_id' => $customer->id,
            'pickup_at' => '2026-08-21 14:20:00',
            'status' => LimoBooking::STATUS_ACTIVE,
        ], $bookingAttrs));

        $leg = LimoLeg::query()->create(array_merge([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 1,
            'from_location' => "A'Ali H174",
            'to_location' => 'Bahrain airport',
            'start_at' => '2026-08-21 14:20:00',
            'vehicle' => 'Suv',
            'rate' => 14, 'net_amount' => 14,
        ], $legAttrs));

        $booking->recalcTotal();
        $booking->save();

        return $leg->refresh();
    }

    /**
     * The Type column read "Transfer" on every row: it used the leg's pricing
     * basis, which defaults to transfer, instead of the job type agreed with
     * the customer.
     */
    public function test_the_type_is_the_booking_type_not_the_pricing_basis(): void
    {
        $leg = $this->leg(['booking_type' => 'airport']);

        $row = app(LimoQueueRows::class)->row($leg);

        $this->assertSame('Airport transfer', $row['type']);
        $this->assertNotSame('Transfer', $row['type']);
    }

    public function test_the_type_falls_back_when_a_booking_has_none_set(): void
    {
        $leg = $this->leg(['booking_type' => null], ['service_type' => LimoLeg::TYPE_CHAUFFEUR]);

        $this->assertSame('Chauffeur', app(LimoQueueRows::class)->row($leg)['type']);
    }

    public function test_the_whatsapp_message_carries_the_trip_and_what_to_collect(): void
    {
        $leg = $this->leg(
            ['booking_type' => 'airport', 'payment_method' => 'cash', 'advance' => 0],
            ['from_location_url' => 'https://maps.app.goo.gl/47LMavbniRwGGThe8'],
        );

        $text = app(LimoQueueRows::class)->whatsappText($leg);

        $this->assertStringContainsString('*Ref. # ' . $leg->reference . '*', $text);
        $this->assertStringContainsString('21-Aug-26', $text);
        $this->assertStringContainsString('14:20', $text);
        $this->assertStringNotContainsString('PM', $text);
        $this->assertStringContainsString('Airport transfer', $text);
        $this->assertStringContainsString('Braxtone Plus W.L.L - +973 39795544', $text);
        // Nothing paid yet, so the driver is told what to bring back.
        $this->assertStringContainsString('Balance 14.000 BD', $text);
        $this->assertStringContainsString('Cash', $text);
        $this->assertStringContainsString("Pick up: A'Ali H174", $text);
        $this->assertStringContainsString('https://maps.app.goo.gl/47LMavbniRwGGThe8', $text);
        $this->assertStringContainsString('Drop off: Bahrain airport', $text);
        // WhatsApp bold, so the car stands out to the driver.
        $this->assertStringContainsString('Car: *Suv*', $text);
    }

    public function test_a_settled_trip_says_paid_instead_of_a_balance(): void
    {
        $leg = $this->leg(['advance' => 14, 'payment_status' => LimoBooking::PAYMENT_PAID]);

        $text = app(LimoQueueRows::class)->whatsappText($leg);

        $this->assertStringContainsString('Paid', $text);
        $this->assertStringNotContainsString('Balance', $text);
    }

    public function test_a_missing_map_link_is_left_out_rather_than_sent_blank(): void
    {
        $leg = $this->leg([], ['from_location_url' => null, 'to_location_url' => null]);

        $text = app(LimoQueueRows::class)->whatsappText($leg);

        $this->assertStringContainsString("Pick up: A'Ali H174", $text);
        $this->assertStringNotContainsString('http', $text);
    }

    /**
     * A company's trips are settled on its account, not by the driver
     * collecting cash from whoever is riding — so neither "collect from
     * customer" nor "Paid" belongs in the message for one.
     */
    public function test_a_company_customer_gets_no_balance_or_paid_line(): void
    {
        $leg = $this->leg(
            ['customer' => ['type' => LimoCustomer::TYPE_COMPANY], 'advance' => 0],
        );

        $text = app(LimoQueueRows::class)->whatsappText($leg);

        $this->assertStringNotContainsString('Balance', $text);
        $this->assertStringNotContainsString('Paid', $text);
        $this->assertStringNotContainsString('✅', $text);
    }

    /** Even fully settled, a company still gets no "Paid" line. */
    public function test_a_settled_company_trip_still_gets_no_paid_line(): void
    {
        $leg = $this->leg([
            'customer' => ['type' => LimoCustomer::TYPE_COMPANY],
            'advance' => 14, 'payment_status' => LimoBooking::PAYMENT_PAID,
        ]);

        $text = app(LimoQueueRows::class)->whatsappText($leg);

        $this->assertStringNotContainsString('Balance', $text);
        $this->assertStringNotContainsString('Paid', $text);
    }

    /**
     * The passenger is who the driver actually meets, and on a company booking
     * is rarely the account the trip is billed to — so the message names them
     * right under the customer.
     */
    public function test_the_message_names_the_passenger_under_the_customer(): void
    {
        $leg = $this->leg(['pax_name' => 'Mr Ahmed Salem', 'pax_contact' => '+968 99200984']);

        $text = app(LimoQueueRows::class)->whatsappText($leg);

        $this->assertStringContainsString('PAX: Mr Ahmed Salem - +968 99200984', $text);
        $this->assertLessThan(
            mb_strpos($text, 'PAX:'),
            mb_strpos($text, 'Customer:'),
            'The passenger belongs after the customer, not before.',
        );
    }

    /** Half the passenger's details still prints; neither drops the line. */
    public function test_a_passenger_with_no_contact_number_still_prints(): void
    {
        $text = app(LimoQueueRows::class)->whatsappText(
            $this->leg(['pax_name' => 'Mr Ahmed Salem', 'pax_contact' => null]),
        );

        $this->assertStringContainsString('PAX: Mr Ahmed Salem', $text);
        $this->assertStringNotContainsString('Mr Ahmed Salem - ', $text);
    }

    public function test_a_booking_with_no_passenger_recorded_sends_no_pax_line(): void
    {
        $text = app(LimoQueueRows::class)->whatsappText(
            $this->leg(['pax_name' => null, 'pax_contact' => null]),
        );

        $this->assertStringNotContainsString('PAX', $text);
    }
    /** An individual customer is unaffected — the balance line stays. */
    public function test_an_individual_customer_still_gets_the_balance_line(): void
    {
        $leg = $this->leg(['customer' => ['type' => 'individual'], 'advance' => 0]);

        $text = app(LimoQueueRows::class)->whatsappText($leg);

        $this->assertStringContainsString('Balance 14.000 BD', $text);
    }
}
