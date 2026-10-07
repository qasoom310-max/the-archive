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

    /**
     * A car is assigned late, often the morning of the trip. Printing only the
     * assigned car left the copied message with no car at all until then.
     */
    public function test_the_message_carries_the_car_type_before_a_car_is_assigned(): void
    {
        $leg = $this->leg([], ['vehicle' => null, 'vehicle_details' => 'Sedan']);

        $text = app(LimoQueueRows::class)->whatsappText($leg);

        $this->assertStringContainsString('Car type: *Sedan*', $text);
        $this->assertStringNotContainsString('Car: ', $text);
    }

    public function test_the_assigned_car_follows_the_type_it_was_asked_for(): void
    {
        $leg = $this->leg([], ['vehicle' => 'Ford Expedition · 363899', 'vehicle_details' => 'SUV']);

        $text = app(LimoQueueRows::class)->whatsappText($leg);

        $this->assertLessThan(
            strpos($text, 'Car: *Ford Expedition · 363899*'),
            strpos($text, 'Car type: *SUV*'),
        );
    }

    public function test_an_imported_trip_falls_back_to_the_bookings_car_type(): void
    {
        $leg = $this->leg(['car_type' => 'Vito'], ['vehicle' => null]);

        $this->assertStringContainsString('Car type: *Vito*', app(LimoQueueRows::class)->whatsappText($leg));
    }

    /** A chauffeur job says when it ends, past midnight included. */
    public function test_a_chauffeur_trip_says_when_it_starts_and_ends(): void
    {
        $leg = $this->leg([], [
            'service_type' => LimoLeg::TYPE_CHAUFFEUR, 'start_at' => '2026-10-07 20:30:00',
            'hours' => 4, 'days' => 1, 'to_location' => null,
        ]);

        $text = app(LimoQueueRows::class)->whatsappText($leg);

        $this->assertStringContainsString('Start: 07-Oct-26 · 20:30', $text);
        $this->assertStringContainsString('End: 08-Oct-26 · 00:30 (4 hours)', $text);
    }

    public function test_a_multi_day_chauffeur_trip_ends_on_its_last_day(): void
    {
        $leg = $this->leg([], [
            'service_type' => LimoLeg::TYPE_CHAUFFEUR, 'start_at' => '2026-10-07 09:00:00',
            'hours' => 8, 'days' => 3,
        ]);

        $text = app(LimoQueueRows::class)->whatsappText($leg);

        $this->assertStringContainsString('Start: 07-Oct-26 · 09:00', $text);
        $this->assertStringContainsString('End: 09-Oct-26 · 17:00 (8 hours × 3 days)', $text);
    }

    /** A transfer keeps its single date line. */
    public function test_a_transfer_has_no_end_line(): void
    {
        $text = app(LimoQueueRows::class)->whatsappText($this->leg());

        $this->assertStringNotContainsString('End:', $text);
        $this->assertStringContainsString('21-Aug-26 · 14:20', $text);
    }

    public function test_a_settled_trip_says_paid_instead_of_a_balance(): void
    {
        $leg = $this->leg(['advance' => 14, 'payment_status' => LimoBooking::PAYMENT_PAID]);

        $text = app(LimoQueueRows::class)->whatsappText($leg);

        $this->assertStringContainsString('Paid', $text);
        $this->assertStringNotContainsString('Balance', $text);
    }

    /**
     * The money line finishes the message, where the office asked for it. Up
     * among the customer's details it read as part of who the trip is for.
     */
    public function test_the_money_line_is_the_last_thing_in_the_message(): void
    {
        $paid = app(LimoQueueRows::class)->whatsappText(
            $this->leg(['advance' => 14, 'payment_status' => LimoBooking::PAYMENT_PAID]),
        );

        $this->assertStringEndsWith('Paid', trim($paid));
        $this->assertLessThan(
            strpos($paid, 'Paid'),
            strpos($paid, 'Drop off'),
            'Paid still comes before the route.',
        );

        // …and a balance to collect finishes it the same way.
        $owing = app(LimoQueueRows::class)->whatsappText(
            $this->leg(['payment_method' => 'cash', 'advance' => 0]),
        );

        $this->assertStringEndsWith('*', trim($owing));
        $this->assertLessThan(
            strpos($owing, 'Balance'),
            strpos($owing, 'Drop off'),
            'The balance still comes before the route.',
        );
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
     * collecting cash from whoever is riding — so a company's message says
     * whether the trip is paid, but never prints an amount to collect.
     */
    public function test_a_company_trip_says_unpaid_without_an_amount(): void
    {
        $leg = $this->leg(
            ['customer' => ['type' => LimoCustomer::TYPE_COMPANY], 'advance' => 0],
        );

        $text = app(LimoQueueRows::class)->whatsappText($leg);

        $this->assertStringEndsWith('Unpaid', trim($text));
        $this->assertStringNotContainsString('Balance', $text);
        $this->assertStringNotContainsString('BD', $text);
    }

    public function test_a_part_paid_company_trip_says_part_paid_without_an_amount(): void
    {
        $leg = $this->leg(
            ['customer' => ['type' => LimoCustomer::TYPE_COMPANY], 'advance' => 5],
        );

        $text = app(LimoQueueRows::class)->whatsappText($leg);

        $this->assertStringEndsWith('Part paid', trim($text));
        $this->assertStringNotContainsString('BD', $text);
    }

    public function test_a_settled_company_trip_says_paid(): void
    {
        $leg = $this->leg([
            'customer' => ['type' => LimoCustomer::TYPE_COMPANY],
            'advance' => 14, 'payment_status' => LimoBooking::PAYMENT_PAID,
        ]);

        $text = app(LimoQueueRows::class)->whatsappText($leg);

        $this->assertStringEndsWith('Paid', trim($text));
        $this->assertStringNotContainsString('Balance', $text);
    }

    /** A private customer who paid a deposit is part paid, and the driver is told what is left. */
    public function test_a_part_paid_private_trip_says_so_and_what_to_collect(): void
    {
        $leg = $this->leg(['advance' => 4, 'payment_method' => 'cash']);

        $text = app(LimoQueueRows::class)->whatsappText($leg);

        $this->assertStringContainsString('Part paid', $text);
        $this->assertStringContainsString('Balance 10.000 BD', $text);
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
