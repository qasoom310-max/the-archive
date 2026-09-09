<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Pricing\PricingPayload;
use App\Erp\Pricing\PricingPortalPing;
use App\Erp\Pricing\PricingVersion;
use App\Erp\Pricing\PricingWriter;
use App\Models\Pricing\PricingCar;
use App\Models\Pricing\PricingOffer;
use App\Models\Pricing\PricingOption;
use App\Models\Pricing\PricingRate;
use App\Models\Pricing\PricingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Modules\Limousine\Models\LimoPortalConfiguration;
use Modules\Limousine\Support\PortalSignature;
use Tests\TestCase;

/**
 * The published-fares API — the contract the WordPress plugin codes against.
 */
final class PricingApiTest extends TestCase
{
    use DatabaseMigrations;

    private const SECRET = 'pricing-test-secret';

    /** Set from the real Main workspace — the id must exist to be readable. */
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        // The shared secret lives on the Limousine portal config row, so that
        // module's tables have to exist before the API can authenticate.
        app(\App\Erp\Modules\ModuleManager::class)->install('limousine');

        $main = app(\App\Erp\Tenancy\WorkspaceManager::class)->ensureMain();
        $this->path = '/api/v1/workspaces/' . $main->getKey() . '/pricing';

        $this->artisan('pricing:seed')->assertSuccessful();

        LimoPortalConfiguration::query()->create([
            'portal_url' => 'https://example.test',
            'shared_secret' => self::SECRET,
            'enabled' => true,
        ]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function signedHeaders(?string $path = null, string $body = '', array $overrides = []): array
    {
        $path ??= $this->path;
        $ts = (string) time();

        return array_merge([
            PortalSignature::TIMESTAMP_HEADER => $ts,
            PortalSignature::SIGNATURE_HEADER => PortalSignature::signRequest('GET', $path, $body, $ts, self::SECRET),
        ], $overrides);
    }

    // --- 1. the payload ---------------------------------------------------

    public function test_a_signed_read_returns_every_service_and_its_real_fares(): void
    {
        $response = $this->withHeaders($this->signedHeaders())->get($this->path);

        $response->assertOk()
            ->assertJsonPath('currency', 'BHD')
            ->assertJsonPath('cars.0.id', 'sedan')
            ->assertJsonPath('cars.0.model', 'Ford Taurus 2024')
            ->assertJsonPath('services.0.id', 'airport')
            ->assertJsonPath('services.0.options.0.code', 'zone_main')
            // The fares the whole project exists to make single-sourced.
            ->assertJsonPath('services.0.options.0.prices.sedan', 15)
            ->assertJsonPath('services.0.options.0.prices.luxury', 50)
            ->assertJsonPath('services.0.options.1.prices.suv', 33);

        // Keyed by id, not position — the order is data and will change again.
        $byId = collect($response->json('services'))->keyBy('id');
        $this->assertSame(
            ['airport', 'city', 'chauffeur', 'ksa', 'bus', 'ksa_bus'],
            array_column($response->json('services'), 'id'),
        );

        // The deliberate, confirmed Luxury jump — 4h 180 → 8h 400.
        $chauffeur = $byId['chauffeur'];
        $this->assertSame(180, $chauffeur['options'][0]['prices']['luxury']);
        $this->assertSame(400, $chauffeur['options'][1]['prices']['luxury']);

        // Extra hours and the return factor ride along where they apply.
        $this->assertSame(12, $chauffeur['extra_hour']['sedan']);
        $this->assertSame(1.8, $byId['ksa']['return_factor']);
        $this->assertNull($byId['airport']['return_factor']);
    }

    public function test_amounts_are_numbers_with_trailing_zeros_trimmed(): void
    {
        $raw = $this->withHeaders($this->signedHeaders())->get($this->path)->getContent();

        // A public page showing "15.000 BHD" is the bug this prevents.
        $this->assertStringContainsString('"sedan":15', (string) $raw);
        $this->assertStringNotContainsString('15.000', (string) $raw);
    }

    // --- 2. auth ----------------------------------------------------------

    public function test_an_unsigned_request_is_rejected(): void
    {
        $this->get($this->path)
            ->assertStatus(401)
            ->assertExactJson(['error' => 'unauthorized']);
    }

    public function test_a_wrong_secret_is_rejected(): void
    {
        $ts = (string) time();

        $this->withHeaders([
            PortalSignature::TIMESTAMP_HEADER => $ts,
            PortalSignature::SIGNATURE_HEADER => PortalSignature::signRequest('GET', $this->path, '', $ts, 'not-the-secret'),
        ])->get($this->path)->assertStatus(401);
    }

    public function test_a_stale_timestamp_is_rejected(): void
    {
        $old = (string) (time() - PortalSignature::MAX_SKEW_SECONDS - 60);

        $this->withHeaders([
            PortalSignature::TIMESTAMP_HEADER => $old,
            PortalSignature::SIGNATURE_HEADER => PortalSignature::signRequest('GET', $this->path, '', $old, self::SECRET),
        ])->get($this->path)->assertStatus(401);
    }

    /**
     * The reason the path-bound variant exists: under the body-only scheme a
     * GET signs nothing but a timestamp, so one captured signature would read
     * every workspace.
     */
    public function test_a_signature_minted_for_another_workspace_is_rejected(): void
    {
        $ts = (string) time();

        $this->withHeaders([
            PortalSignature::TIMESTAMP_HEADER => $ts,
            PortalSignature::SIGNATURE_HEADER => PortalSignature::signRequest(
                'GET',
                '/api/v1/workspaces/9/pricing',
                '',
                $ts,
                self::SECRET,
            ),
        ])->get($this->path)->assertStatus(401);
    }

    public function test_an_unknown_workspace_never_falls_through_to_this_database(): void
    {
        $path = '/api/v1/workspaces/999/pricing';

        $this->withHeaders($this->signedHeaders($path))
            ->get($path)
            ->assertStatus(401);
    }

    // --- 3. caching -------------------------------------------------------

    public function test_a_matching_etag_returns_304_with_no_body(): void
    {
        $first = $this->withHeaders($this->signedHeaders())->get($this->path)->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertSame('"' . PricingVersion::current() . '"', $etag);

        $second = $this->withHeaders($this->signedHeaders(overrides: ['If-None-Match' => (string) $etag]))
            ->get($this->path);

        $second->assertStatus(304);
        $this->assertSame('', $second->getContent());
    }

    // --- 4. the version ---------------------------------------------------

    public function test_saving_a_whole_grid_bumps_the_version_by_exactly_one(): void
    {
        $before = PricingVersion::current();
        $option = PricingOption::query()->where('code', 'zone_main')->firstOrFail();

        app(PricingWriter::class)->transaction('Airport Transfer', function () use ($option): array {
            $writer = app(PricingWriter::class);

            // A real grid save touches every cell, not one.
            return array_values(array_filter([
                $writer->setRate($option, 'sedan', 18),
                $writer->setRate($option, 'suv', 24),
                $writer->setRate($option, 'lsuv', 34),
                $writer->setRate($option, 'luxury', 55),
            ]));
        });

        $this->assertSame($before + 1, PricingVersion::current());
        $this->assertEqualsWithDelta(18.0, (float) PricingRate::query()
            ->where('option_id', $option->id)->where('car_id', 'sedan')->value('amount'), 0.001);
    }

    public function test_a_save_that_changes_nothing_does_not_bump_the_version(): void
    {
        $before = PricingVersion::current();
        $option = PricingOption::query()->where('code', 'zone_main')->firstOrFail();

        app(PricingWriter::class)->transaction('Airport Transfer', function () use ($option): array {
            // 15 is already the stored fare.
            return array_values(array_filter([app(PricingWriter::class)->setRate($option, 'sedan', 15)]));
        });

        $this->assertSame($before, PricingVersion::current());
    }

    public function test_a_change_is_recorded_in_the_activity_log(): void
    {
        $option = PricingOption::query()->where('code', 'zone_main')->firstOrFail();

        app(PricingWriter::class)->transaction('Airport Transfer', fn (): array => array_values(array_filter([
            app(PricingWriter::class)->setRate($option, 'sedan', 18),
        ])));

        $log = \App\Models\ActivityLog::query()->where('action', 'pricing_updated')->firstOrFail();
        $this->assertSame('Airport Transfer', $log->subject);
        $this->assertStringContainsString('zone_main/sedan 15 → 18', (string) $log->description);
    }

    // --- 5. what is published --------------------------------------------

    public function test_an_inactive_service_disappears_entirely(): void
    {
        PricingService::query()->whereKey('ksa')->update(['active' => false]);

        $ids = array_column(
            $this->withHeaders($this->signedHeaders())->get($this->path)->json('services'),
            'id',
        );

        $this->assertNotContains('ksa', $ids);
        $this->assertContains('airport', $ids);
    }

    public function test_a_car_with_no_fare_is_omitted_rather_than_published_as_zero(): void
    {
        $option = PricingOption::query()->where('code', 'zone_main')->firstOrFail();
        PricingRate::query()->where('option_id', $option->id)->where('car_id', 'luxury')->delete();

        $prices = $this->withHeaders($this->signedHeaders())
            ->get($this->path)
            ->json('services.0.options.0.prices');

        $this->assertArrayNotHasKey('luxury', $prices);
        $this->assertSame(15, $prices['sedan']);
    }

    public function test_an_inactive_car_is_dropped_from_the_cars_list_and_every_price(): void
    {
        PricingCar::query()->whereKey('luxury')->update(['active' => false]);

        $body = $this->withHeaders($this->signedHeaders())->get($this->path)->json();

        $this->assertNotContains('luxury', array_column($body['cars'], 'id'));
        $this->assertArrayNotHasKey('luxury', $body['services'][0]['options'][0]['prices']);
    }

    // --- 6. offers --------------------------------------------------------

    public function test_an_expired_offer_is_published_as_inactive(): void
    {
        PricingOffer::query()->where('service_id', 'airport')->update([
            'active' => true, 'percent' => 25,
            'label_en' => 'National Day 25%',
            'starts_at' => CarbonImmutable::now()->subDays(10)->toDateString(),
            'ends_at' => CarbonImmutable::now()->subDay()->toDateString(),
        ]);

        $offer = $this->withHeaders($this->signedHeaders())->get($this->path)->json('services.0.offer');

        $this->assertFalse($offer['active']);
        $this->assertSame(0, $offer['percent']);
        $this->assertNull($offer['label_en']);
    }

    public function test_an_offer_that_has_not_started_is_published_as_inactive(): void
    {
        PricingOffer::query()->where('service_id', 'airport')->update([
            'active' => true, 'percent' => 25,
            'starts_at' => CarbonImmutable::now()->addDay()->toDateString(),
            'ends_at' => CarbonImmutable::now()->addDays(10)->toDateString(),
        ]);

        $this->assertFalse(
            $this->withHeaders($this->signedHeaders())->get($this->path)->json('services.0.offer.active'),
        );
    }

    public function test_a_live_offer_is_published_with_its_percent_and_labels(): void
    {
        PricingOffer::query()->where('service_id', 'airport')->update([
            'active' => true, 'percent' => 25,
            'label_en' => 'National Day 25%', 'label_ar' => 'اليوم الوطني ٢٥٪',
            'starts_at' => CarbonImmutable::now()->subDay()->toDateString(),
            'ends_at' => CarbonImmutable::now()->addDays(5)->toDateString(),
        ]);

        $offer = $this->withHeaders($this->signedHeaders())->get($this->path)->json('services.0.offer');

        $this->assertTrue($offer['active']);
        $this->assertSame(25, $offer['percent']);
        $this->assertSame('National Day 25%', $offer['label_en']);
    }

    // --- 7. the ping ------------------------------------------------------

    public function test_the_ping_is_signed_and_carries_only_the_version(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $result = app(PricingPortalPing::class)->send(48, force: true);
        $this->assertTrue($result['sent']);

        Http::assertSent(function ($request): bool {
            $this->assertSame('https://example.test/wp-json/wanaan/v1/pricing/refresh', $request->url());

            $body = $request->body();
            // No prices ever leave this way — the site comes and fetches.
            $this->assertStringNotContainsString('prices', $body);
            $this->assertStringContainsString('"version":48', $body);

            $ts = $request->header(PortalSignature::TIMESTAMP_HEADER)[0];
            $expected = PortalSignature::signRequest(
                'POST',
                '/wp-json/wanaan/v1/pricing/refresh',
                $body,
                $ts,
                self::SECRET,
            );

            return $request->header(PortalSignature::SIGNATURE_HEADER)[0] === $expected;
        });
    }

    public function test_an_unreachable_website_never_breaks_a_save(): void
    {
        Http::fake(function (): void {
            throw new \RuntimeException('Connection refused');
        });

        $option = PricingOption::query()->where('code', 'zone_main')->firstOrFail();

        $version = app(PricingWriter::class)->transaction('Airport Transfer', fn (): array => array_values(array_filter([
            app(PricingWriter::class)->setRate($option, 'sedan', 19),
        ])));

        $result = app(PricingPortalPing::class)->send($version, force: true);

        // The price is saved and the version moved; only the ping failed.
        $this->assertFalse($result['sent']);
        $this->assertEqualsWithDelta(19.0, (float) PricingRate::query()
            ->where('option_id', $option->id)->where('car_id', 'sedan')->value('amount'), 0.001);
    }

    public function test_a_burst_of_saves_sends_one_ping(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $ping = app(PricingPortalPing::class);
        $first = $ping->send(1);
        $second = $ping->send(2);

        $this->assertTrue($first['sent']);
        $this->assertFalse($second['sent']);
        Http::assertSentCount(1);
    }

    // --- 8. performance ---------------------------------------------------

    public function test_the_full_payload_builds_well_inside_the_budget(): void
    {
        $started = microtime(true);
        $payload = app(PricingPayload::class)->build();
        $elapsed = (microtime(true) - $started) * 1000;

        $this->assertCount(6, $payload['services']);
        $this->assertLessThan(500, $elapsed, 'Payload build exceeded the 500ms budget.');
    }

    // --- 9. the admin screen ---------------------------------------------

    public function test_the_fares_screen_is_admin_only(): void
    {
        $this->actingAs(\App\Models\User::factory()->create());
        $this->get('/fares')->assertForbidden();

        $this->actingAs(\App\Models\User::factory()->create(['is_admin' => true]));
        $this->get('/fares')->assertOk()->assertSee('Airport Transfer');
    }

    public function test_saving_the_grid_writes_every_cell_and_bumps_once(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->actingAs(\App\Models\User::factory()->create(['is_admin' => true]));

        $before = PricingVersion::current();
        $option = PricingOption::query()->where('service_id', 'airport')->where('code', 'zone_main')->firstOrFail();

        $component = \Livewire\Livewire::test(\App\Livewire\Pages\PricingManager::class)
            ->set('service', 'airport');

        $component->set("rates.{$option->id}.sedan", '17')
            ->set("rates.{$option->id}.luxury", '55')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($before + 1, PricingVersion::current());
        $this->assertEqualsWithDelta(17.0, (float) PricingRate::query()
            ->where('option_id', $option->id)->where('car_id', 'sedan')->value('amount'), 0.001);
        Http::assertSentCount(1);
    }

    public function test_an_active_option_cannot_be_saved_with_a_blank_fare(): void
    {
        $this->actingAs(\App\Models\User::factory()->create(['is_admin' => true]));

        $option = PricingOption::query()->where('service_id', 'airport')->where('code', 'zone_main')->firstOrFail();
        $before = PricingVersion::current();

        \Livewire\Livewire::test(\App\Livewire\Pages\PricingManager::class)
            ->set('service', 'airport')
            ->set("rates.{$option->id}.luxury", '')
            ->call('save')
            ->assertHasErrors("rates.{$option->id}.luxury");

        // Nothing was published — the version did not move.
        $this->assertSame($before, PricingVersion::current());
    }

    public function test_a_blank_fare_is_allowed_when_the_option_is_switched_off(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->actingAs(\App\Models\User::factory()->create(['is_admin' => true]));

        $option = PricingOption::query()->where('service_id', 'airport')->where('code', 'zone_south')->firstOrFail();

        \Livewire\Livewire::test(\App\Livewire\Pages\PricingManager::class)
            ->set('service', 'airport')
            ->set("optionActive.{$option->id}", false)
            ->set("rates.{$option->id}.luxury", '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(PricingRate::query()
            ->where('option_id', $option->id)->where('car_id', 'luxury')->first());
    }

    public function test_a_fare_with_too_many_decimals_is_refused(): void
    {
        $this->actingAs(\App\Models\User::factory()->create(['is_admin' => true]));
        $option = PricingOption::query()->where('service_id', 'airport')->where('code', 'zone_main')->firstOrFail();

        \Livewire\Livewire::test(\App\Livewire\Pages\PricingManager::class)
            ->set('service', 'airport')
            ->set("rates.{$option->id}.sedan", '15.12345')
            ->call('save')
            ->assertHasErrors("rates.{$option->id}.sedan");
    }

    public function test_the_manual_button_forces_a_ping_past_the_debounce(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->actingAs(\App\Models\User::factory()->create(['is_admin' => true]));

        \Livewire\Livewire::test(\App\Livewire\Pages\PricingManager::class)
            ->call('sendToWebsite')
            ->assertSet('pingOk', true);

        \Livewire\Livewire::test(\App\Livewire\Pages\PricingManager::class)
            ->call('sendToWebsite')
            ->assertSet('pingOk', true);

        Http::assertSentCount(2);
    }

    // --- 10. v3: buses, per-service vehicles, settings, estimates ---------

    public function test_all_six_services_and_eight_vehicles_are_published(): void
    {
        $body = $this->withHeaders($this->signedHeaders())->get($this->path)->json();

        $this->assertSame(
            ['airport', 'city', 'chauffeur', 'ksa', 'bus', 'ksa_bus'],
            array_column($body['services'], 'id'),
        );
        $this->assertSame(
            ['sedan', 'suv', 'lsuv', 'luxury', 'hiace', 'coaster', 'coach', 'sprinter'],
            array_column($body['cars'], 'id'),
        );
    }

    public function test_a_service_publishes_only_the_vehicles_it_offers(): void
    {
        $body = $this->withHeaders($this->signedHeaders())->get($this->path)->json();
        $byId = collect($body['services'])->keyBy('id');

        // The airport widget must never offer a 50-seat coach.
        $this->assertSame(['sedan', 'suv', 'lsuv', 'luxury'], $byId['airport']['cars']);
        $this->assertSame(['hiace', 'coaster', 'coach', 'sprinter'], $byId['bus']['cars']);

        $this->assertArrayNotHasKey('coach', $byId['airport']['options'][0]['prices']);
        $this->assertArrayNotHasKey('sedan', $byId['bus']['options'][0]['prices']);
    }

    public function test_bus_blocks_are_six_eight_twelve_not_the_car_blocks(): void
    {
        $body = $this->withHeaders($this->signedHeaders())->get($this->path)->json();
        $bus = collect($body['services'])->firstWhere('id', 'bus');

        $this->assertSame(['h6', 'h8', 'h12'], array_column($bus['options'], 'code'));
        $this->assertSame([6, 8, 12], array_column($bus['options'], 'hours'));
        $this->assertSame(70, $bus['options'][0]['prices']['hiace']);
        $this->assertSame(320, $bus['options'][2]['prices']['sprinter']);
    }

    public function test_a_bus_reports_null_bags_rather_than_an_invented_number(): void
    {
        $body = $this->withHeaders($this->signedHeaders())->get($this->path)->json();
        $cars = collect($body['cars'])->keyBy('id');

        $this->assertSame(50, $cars['coach']['pax']);
        $this->assertNull($cars['coach']['bags']);
        $this->assertSame(2, $cars['sedan']['bags']);
    }

    public function test_the_settings_ride_along_with_the_fares(): void
    {
        $body = $this->withHeaders($this->signedHeaders())->get($this->path)->json();

        $this->assertSame('97317474949', $body['settings']['whatsapp']);
        $this->assertSame(12, $body['settings']['lead_hours']);
    }

    public function test_the_estimated_flag_never_reaches_the_website(): void
    {
        $raw = (string) $this->withHeaders($this->signedHeaders())->get($this->path)->getContent();

        // It is an internal warning for the admin screen, not customer copy.
        $this->assertStringNotContainsString('estimated', $raw);
        $this->assertTrue(PricingService::query()->whereKey('ksa_bus')->value('estimated'));
    }

    public function test_a_service_with_no_positive_fare_is_never_published(): void
    {
        // Half-configured: the service exists but nothing is priced.
        $service = PricingService::query()->whereKey('bus')->firstOrFail();
        PricingRate::query()
            ->whereIn('option_id', PricingOption::query()->where('service_id', 'bus')->pluck('id'))
            ->delete();

        $ids = array_column(
            $this->withHeaders($this->signedHeaders())->get($this->path)->json('services'),
            'id',
        );

        $this->assertNotContains('bus', $ids);
        $this->assertContains('airport', $ids);
        $this->assertNotNull($service);
    }

    public function test_saving_an_estimated_service_clears_its_warning(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->actingAs(\App\Models\User::factory()->create(['is_admin' => true]));

        $this->assertTrue(PricingService::query()->whereKey('ksa_bus')->value('estimated'));

        \Livewire\Livewire::test(\App\Livewire\Pages\PricingManager::class)
            ->set('service', 'ksa_bus')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse((bool) PricingService::query()->whereKey('ksa_bus')->value('estimated'));
    }

    public function test_the_settings_are_editable_and_validated(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->actingAs(\App\Models\User::factory()->create(['is_admin' => true]));

        \Livewire\Livewire::test(\App\Livewire\Pages\PricingManager::class)
            ->set('service', 'airport')
            ->set('whatsapp', 'not digits')
            ->call('save')
            ->assertHasErrors('whatsapp');

        \Livewire\Livewire::test(\App\Livewire\Pages\PricingManager::class)
            ->set('service', 'airport')
            ->set('whatsapp', '97333001122')
            ->set('leadHours', '24')
            ->call('save')
            ->assertHasNoErrors();

        $settings = \App\Models\Pricing\PricingSetting::current();
        $this->assertSame('97333001122', $settings->whatsapp);
        $this->assertSame(24, $settings->lead_hours);
    }

    public function test_the_admin_grid_shows_only_the_open_services_vehicles(): void
    {
        $this->actingAs(\App\Models\User::factory()->create(['is_admin' => true]));

        \Livewire\Livewire::test(\App\Livewire\Pages\PricingManager::class)
            ->set('service', 'bus')
            ->assertSee('Toyota Coaster')
            ->assertDontSee('Ford Taurus 2024');
    }
}
