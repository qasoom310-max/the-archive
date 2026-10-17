<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Tenancy\WorkspaceManager;
use App\Livewire\Pages\PricingManager;
use App\Models\Pricing\PricingSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoLocation;
use Modules\Limousine\Models\LimoPortalConfiguration;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Support\BorderOffers;
use Modules\Limousine\Support\PortalSignature;
use Tests\TestCase;

/**
 * The cross-border empty-car offer: a car that must cross between Bahrain and
 * Saudi Arabia empty anyway is sold at a discount at the hours it fits.
 */
final class BorderOffersTest extends TestCase
{
    use DatabaseMigrations;

    private const SECRET = 'border-test-secret';

    private string $base;

    private LimoCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('limousine');

        // Tuesday 20 Oct 2026, midnight, Bahrain.
        Carbon::setTestNow('2026-10-20 00:00:00');

        PricingSetting::query()->create([
            'whatsapp' => '97339991869',
            'lead_hours' => 12,
            'border_enabled' => true,
            'border_discount_percent' => 30,
            'border_travel_hours' => 2,
            'border_wait_hours' => 4,
            'border_notice_hours' => 2,
        ]);

        LimoPortalConfiguration::query()->create([
            'portal_url' => 'https://example.test', 'shared_secret' => self::SECRET, 'enabled' => true,
        ]);

        $main = app(WorkspaceManager::class)->ensureMain();
        $this->base = '/api/v1/workspaces/' . $main->getKey() . '/border-offers';

        $this->customer = LimoCustomer::query()->create(['name' => 'Traveller']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $leg
     * @param  array<string, mixed>  $booking
     */
    private function trip(string $from, ?string $fromCountry, string $to, ?string $toCountry, string $start, array $leg = [], array $booking = []): LimoLeg
    {
        $parent = LimoBooking::query()->create(['customer_id' => $this->customer->id] + $booking);

        return $parent->legs()->create($leg + [
            'sequence' => 0,
            'service_type' => LimoLeg::TYPE_TRANSFER,
            'status' => LimoLeg::STATUS_QUEUE,
            'from_location' => $from, 'from_country' => $fromCountry,
            'to_location' => $to, 'to_country' => $toCountry,
            'start_at' => $start,
            'rate' => 100, 'rate_basis' => LimoLeg::BASIS_TRIP, 'days' => 1, 'units' => 1,
        ]);
    }

    /** @return list<int> the discounted hours of a day */
    private function discounted(string $from, string $to, string $date): array
    {
        $hours = BorderOffers::make()->hours($from, $to, CarbonImmutable::parse($date));

        return array_values(array_map(
            static fn (array $h): int => $h['hour'],
            array_filter($hours, static fn (array $h): bool => $h['discount']),
        ));
    }

    // --- Where the cars will be ---------------------------------------------

    public function test_a_trip_into_saudi_leaves_a_car_that_returns_at_a_discount(): void
    {
        // Leaves Manama at 01:00, in Riyadh at 03:00, may wait there until 07:00.
        $this->trip('Manama', 'BH', 'Riyadh', 'SA', '2026-10-21 01:00');

        $this->assertSame([3, 4, 5, 6, 7], $this->discounted('SA', 'BH', '2026-10-21'));
        // The owner's example: wanting 02:00 there is no car yet; 03:00 has one.
        $hours = BorderOffers::make()->hours('SA', 'BH', CarbonImmutable::parse('2026-10-21'));
        $this->assertFalse($hours[2]['discount']);
        $this->assertTrue($hours[3]['discount']);
        $this->assertSame(1, $hours[3]['cars']);
    }

    public function test_the_other_direction_is_not_discounted_by_that_trip(): void
    {
        $this->trip('Manama', 'BH', 'Riyadh', 'SA', '2026-10-21 01:00');

        $this->assertSame([], $this->discounted('BH', 'SA', '2026-10-21'));
    }

    public function test_a_pick_up_in_saudi_sends_a_car_out_empty_at_a_discount(): void
    {
        // Picks up in Dammam at 12:00, so a car leaves Bahrain between 06:00
        // (waiting at most 4 hours there) and 10:00 (2 hours to drive).
        $this->trip('Dammam', 'SA', 'Bahrain Airport', 'BH', '2026-10-21 12:00');

        $this->assertSame([6, 7, 8, 9, 10], $this->discounted('BH', 'SA', '2026-10-21'));
        $this->assertSame([], $this->discounted('SA', 'BH', '2026-10-21'));
    }

    public function test_a_booked_offer_takes_the_car_so_it_is_not_offered_again(): void
    {
        $this->trip('Manama', 'BH', 'Riyadh', 'SA', '2026-10-21 01:00');
        // The customer takes the 04:00 ride back.
        $this->trip('Riyadh', 'SA', 'Manama', 'BH', '2026-10-21 04:00');

        $this->assertSame([], $this->discounted('SA', 'BH', '2026-10-21'));
        // And the ride back does not invent a car sent out for it.
        $this->assertSame([], $this->discounted('BH', 'SA', '2026-10-21'));
    }

    public function test_a_car_kept_in_saudi_for_its_next_job_is_not_offered(): void
    {
        $this->trip('Manama', 'BH', 'Riyadh', 'SA', '2026-10-21 01:00', ['car_id' => 5]);
        // The same car runs a chauffeur day in Riyadh the next morning.
        $this->trip('Riyadh', 'SA', '', null, '2026-10-22 10:00', [
            'car_id' => 5, 'service_type' => LimoLeg::TYPE_CHAUFFEUR, 'hours' => 8, 'rate_basis' => LimoLeg::BASIS_HOUR,
        ]);

        $this->assertSame([], $this->discounted('SA', 'BH', '2026-10-21'));
        $this->assertSame([], $this->discounted('BH', 'SA', '2026-10-22'));
    }

    public function test_a_car_due_back_for_its_next_job_is_offered_only_until_it_must_leave(): void
    {
        $this->trip('Manama', 'BH', 'Khobar', 'SA', '2026-10-21 01:00', ['car_id' => 7]);
        // Needed in Bahrain at 06:00, so it must leave Saudi by 04:00.
        $this->trip('Seef', 'BH', 'Juffair', 'BH', '2026-10-21 06:00', ['car_id' => 7]);

        $this->assertSame([3, 4], $this->discounted('SA', 'BH', '2026-10-21'));
    }

    public function test_two_cars_on_one_trip_are_two_cars_on_offer(): void
    {
        $this->trip('Manama', 'BH', 'Riyadh', 'SA', '2026-10-21 01:00', ['units' => 2]);

        $hours = BorderOffers::make()->hours('SA', 'BH', CarbonImmutable::parse('2026-10-21'));
        $this->assertSame(2, $hours[3]['cars']);
    }

    public function test_cancelled_trips_and_quotations_put_no_car_anywhere(): void
    {
        $this->trip('Manama', 'BH', 'Riyadh', 'SA', '2026-10-21 01:00', [], ['status' => LimoBooking::STATUS_CANCELLED]);
        $this->trip('Manama', 'BH', 'Riyadh', 'SA', '2026-10-21 01:00', ['status' => LimoLeg::STATUS_CANCELLED]);

        $quote = LimoQuotation::query()->create(['customer_id' => $this->customer->id]);
        $quote->legs()->create([
            'sequence' => 0, 'service_type' => LimoLeg::TYPE_TRANSFER,
            'from_location' => 'Manama', 'from_country' => 'BH', 'to_location' => 'Riyadh', 'to_country' => 'SA',
            'start_at' => '2026-10-21 01:00', 'rate' => 100, 'rate_basis' => LimoLeg::BASIS_TRIP, 'days' => 1,
        ]);

        $this->assertSame([], $this->discounted('SA', 'BH', '2026-10-21'));
    }

    public function test_an_older_trip_is_placed_by_its_place_name(): void
    {
        // Saved before trips had a country.
        $this->trip('Manama', null, 'Hilton Riyadh', null, '2026-10-21 01:00');
        LimoLocation::query()->create(['name' => 'Jubail Industrial', 'country' => 'SA']);
        $this->trip('Jubail Industrial', null, 'Seef', null, '2026-10-21 20:00');

        $this->assertSame([3, 4, 5, 6, 7], $this->discounted('SA', 'BH', '2026-10-21'));
        $this->assertSame([14, 15, 16, 17, 18], $this->discounted('BH', 'SA', '2026-10-21'));
    }

    // --- What a customer may book -------------------------------------------

    public function test_hours_too_soon_are_neither_discounted_nor_bookable(): void
    {
        $this->trip('Manama', 'BH', 'Riyadh', 'SA', '2026-10-21 01:00');
        Carbon::setTestNow('2026-10-21 02:30:00');

        $hours = BorderOffers::make()->hours('SA', 'BH', CarbonImmutable::parse('2026-10-21'));

        // The offer needs 2 hours' notice: 04:30 at the earliest.
        $this->assertFalse($hours[4]['discount']);
        $this->assertFalse($hours[4]['bookable']);
        $this->assertTrue($hours[5]['discount']);
        $this->assertTrue($hours[5]['bookable']);
        // A full-fare hour needs the website's usual 12 hours: 14:30.
        $this->assertFalse($hours[14]['bookable']);
        $this->assertTrue($hours[15]['bookable']);
        $this->assertFalse($hours[15]['discount']);
    }

    public function test_the_offer_can_be_switched_off(): void
    {
        $this->trip('Manama', 'BH', 'Riyadh', 'SA', '2026-10-21 01:00');
        PricingSetting::current()->update(['border_enabled' => false]);

        $this->assertSame([], $this->discounted('SA', 'BH', '2026-10-21'));
        $this->assertSame([], BorderOffers::make()->upcoming('SA', 'BH'));
    }

    public function test_the_wait_and_drive_times_come_from_the_settings(): void
    {
        PricingSetting::current()->update(['border_travel_hours' => 3, 'border_wait_hours' => 1]);
        $this->trip('Manama', 'BH', 'Riyadh', 'SA', '2026-10-21 01:00');

        $this->assertSame([4, 5], $this->discounted('SA', 'BH', '2026-10-21'));
    }

    public function test_a_search_inside_one_country_never_has_the_offer(): void
    {
        $this->trip('Manama', 'BH', 'Riyadh', 'SA', '2026-10-21 01:00');

        $this->assertSame([], $this->discounted('SA', 'SA', '2026-10-21'));
        $this->assertSame([], $this->discounted('BH', 'BH', '2026-10-21'));
    }

    public function test_the_next_offers_are_listed_soonest_first(): void
    {
        $this->trip('Manama', 'BH', 'Riyadh', 'SA', '2026-10-23 09:00');
        $this->trip('Manama', 'BH', 'Dammam', 'SA', '2026-10-21 01:30');

        $this->assertSame([
            ['date' => '2026-10-21', 'hour' => 4, 'time' => '04:00'],
            ['date' => '2026-10-23', 'hour' => 11, 'time' => '11:00'],
        ], BorderOffers::make()->upcoming('SA', 'BH'));
    }

    // --- The website's API ----------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function signed(string $uri): array
    {
        $ts = (string) time();

        return [
            PortalSignature::TIMESTAMP_HEADER => $ts,
            PortalSignature::SIGNATURE_HEADER => PortalSignature::signRequest('GET', $uri, '', $ts, self::SECRET),
        ];
    }

    public function test_a_signed_search_returns_the_days_hours(): void
    {
        $this->trip('Manama', 'BH', 'Riyadh', 'SA', '2026-10-21 01:00');
        $uri = $this->base . '?from=SA&to=BH&date=2026-10-21';

        $this->withHeaders($this->signed($uri))->get($uri)
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('discount_percent', 30)
            ->assertJsonPath('whatsapp', '97339991869')
            ->assertJsonPath('query.from', 'SA')
            ->assertJsonPath('countries.1.code', 'SA')
            ->assertJsonPath('countries.1.name_ar', 'السعودية')
            ->assertJsonPath('hours.3.discount', true)
            ->assertJsonPath('hours.2.discount', false)
            ->assertJsonCount(24, 'hours')
            ->assertJsonPath('upcoming.0.date', '2026-10-21');
    }

    public function test_the_answer_names_no_booking_customer_or_car(): void
    {
        $this->trip('Manama', 'BH', 'Riyadh', 'SA', '2026-10-21 01:00', ['car_id' => 5]);
        $uri = $this->base . '?from=SA&to=BH&date=2026-10-21';

        $raw = (string) $this->withHeaders($this->signed($uri))->get($uri)->getContent();

        $this->assertStringNotContainsString('Traveller', $raw);
        $this->assertStringNotContainsString('Riyadh', $raw);
        $this->assertStringNotContainsString('BK/', $raw);
    }

    public function test_an_unsigned_search_is_refused(): void
    {
        $this->get($this->base . '?from=SA&to=BH&date=2026-10-21')
            ->assertStatus(401)
            ->assertExactJson(['error' => 'unauthorized']);
    }

    public function test_a_signature_does_not_carry_over_to_another_question(): void
    {
        $signedFor = $this->base . '?from=SA&to=BH&date=2026-10-21';

        $this->withHeaders($this->signed($signedFor))->get($this->base . '?from=BH&to=SA&date=2026-10-21')
            ->assertStatus(401);
    }

    public function test_an_unknown_database_is_refused(): void
    {
        $uri = '/api/v1/workspaces/999/border-offers?from=SA&to=BH&date=2026-10-21';

        $this->withHeaders($this->signed($uri))->get($uri)->assertStatus(401);
    }

    public function test_a_bad_question_is_answered_422(): void
    {
        foreach ([
            '?from=QA&to=BH&date=2026-10-21',
            '?from=SA&to=BH&date=21-10-2026',
            '?from=SA&to=BH&date=2026-02-30',
            '?from=SA&to=BH&date=2026-10-19',
            '?from=SA&to=BH&date=2027-06-01',
        ] as $query) {
            $uri = $this->base . $query;
            $this->withHeaders($this->signed($uri))->get($uri)->assertStatus(422);
        }
    }

    // --- The owner's settings -------------------------------------------------

    public function test_the_offer_is_set_on_the_fares_screen(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->artisan('pricing:seed')->assertSuccessful();
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(PricingManager::class)
            ->set('service', 'airport')
            ->assertSet('borderDiscount', '30')
            ->set('borderDiscount', '150')
            ->call('save')
            ->assertHasErrors('borderDiscount');

        Livewire::test(PricingManager::class)
            ->set('service', 'airport')
            ->set('borderEnabled', false)
            ->set('borderDiscount', '25')
            ->set('borderTravelHours', '1.5')
            ->set('borderWaitHours', '3')
            ->set('borderNoticeHours', '1')
            ->call('save')
            ->assertHasNoErrors();

        $settings = PricingSetting::current();
        $this->assertFalse($settings->border_enabled);
        $this->assertSame(25.0, $settings->border_discount_percent);
        $this->assertSame(1.5, $settings->border_travel_hours);
        $this->assertSame(3, $settings->border_wait_hours);
        $this->assertSame(1, $settings->border_notice_hours);
    }
}
