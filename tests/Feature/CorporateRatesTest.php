<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Pricing\FareCalculator;
use App\Erp\Pricing\FareResult;
use App\Erp\Pricing\PricingPayload;
use App\Erp\Pricing\PricingVersion;
use App\Livewire\Pages\CorporateRates;
use App\Models\Pricing\PricingCorporateRate;
use App\Models\Pricing\PricingOffer;
use App\Models\Pricing\PricingOption;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Modules\Limousine\Models\LimoCustomer;
use Tests\TestCase;

/**
 * Corporate rates: prices agreed with companies, kept apart from the website
 * fares. A company's own deal wins, then the standard corporate rate, then
 * the website fare — and none of it is ever published to the website.
 */
final class CorporateRatesTest extends TestCase
{
    use DatabaseMigrations;

    private PricingOption $zoneMain;

    private LimoCustomer $turbo;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('limousine');
        Artisan::call('pricing:seed');

        $this->zoneMain = PricingOption::query()->where('service_id', 'airport')->where('code', 'zone_main')->firstOrFail();
        $this->turbo = LimoCustomer::query()->create(['name' => 'Turbo Engineering', 'type' => LimoCustomer::TYPE_COMPANY]);
    }

    private function rate(?int $customerId, float $amount, string $car = 'sedan'): void
    {
        PricingCorporateRate::query()->create([
            'customer_id' => $customerId, 'option_id' => $this->zoneMain->id, 'car_id' => $car, 'amount' => $amount,
        ]);
    }

    private function quote(?int $companyId): FareResult
    {
        return app(FareCalculator::class)->quote('airport', 'sedan', 'zone_main', companyId: $companyId);
    }

    public function test_a_company_pays_its_own_rate_then_the_standard_one_then_the_website_fare(): void
    {
        // Nothing set: the website fare.
        $this->assertSame(15.0, $this->quote($this->turbo->id)->total);
        $this->assertSame(FareResult::SOURCE_WEBSITE, $this->quote($this->turbo->id)->source);

        // The standard corporate rate every company gets.
        $this->rate(null, 12);
        $this->assertSame(12.0, $this->quote($this->turbo->id)->total);
        $this->assertSame(FareResult::SOURCE_CORPORATE_STANDARD, $this->quote($this->turbo->id)->source);

        // Turbo's own deal beats it.
        $this->rate($this->turbo->id, 10);
        $this->assertSame(10.0, $this->quote($this->turbo->id)->total);
        $this->assertSame(FareResult::SOURCE_CORPORATE, $this->quote($this->turbo->id)->source);

        // Another company gets the standard rate, not Turbo's.
        $other = LimoCustomer::query()->create(['name' => 'Braxtone', 'type' => LimoCustomer::TYPE_COMPANY]);
        $this->assertSame(12.0, $this->quote($other->id)->total);

        // Without a company it is always the website fare.
        $this->assertSame(15.0, $this->quote(null)->total);
    }

    public function test_a_website_offer_does_not_come_off_a_corporate_price(): void
    {
        PricingOffer::query()->updateOrCreate(['service_id' => 'airport'], ['active' => true, 'percent' => 50]);
        $this->rate($this->turbo->id, 10);

        $fare = app(FareCalculator::class)->quote('airport', 'sedan', 'zone_main', travelDate: CarbonImmutable::now(), companyId: $this->turbo->id);

        $this->assertSame(10.0, $fare->total);
        $this->assertSame(0.0, $fare->discount);
    }

    public function test_a_route_hidden_from_the_website_can_still_carry_a_corporate_rate(): void
    {
        $this->zoneMain->update(['active' => false]);
        $this->rate($this->turbo->id, 9);

        $this->assertSame(9.0, $this->quote($this->turbo->id)->total);
        // The public still cannot book it, and a company without a deal
        // does not get the hidden website fare either.
        $this->assertFalse($this->quote(null)->found);
        $other = LimoCustomer::query()->create(['name' => 'Braxtone', 'type' => LimoCustomer::TYPE_COMPANY]);
        $this->assertFalse($this->quote($other->id)->found);
    }

    public function test_the_page_saves_standard_and_company_rates_and_clears_a_blank(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $option = $this->zoneMain->id;

        Livewire::test(CorporateRates::class)
            ->assertSee('Corporate rates')
            ->set("rates.{$option}.sedan", '12')
            ->call('save')
            ->assertHasNoErrors()
            ->set('company', (string) $this->turbo->id)
            ->assertSet("rates.{$option}.sedan", '')
            ->set("rates.{$option}.sedan", '10.5')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Turbo Engineering');

        $this->assertSame(12.0, PricingCorporateRate::query()->whereNull('customer_id')->sole()->amount);
        $this->assertSame(10.5, PricingCorporateRate::query()->where('customer_id', $this->turbo->id)->sole()->amount);

        // Clearing the cell removes Turbo's deal; the standard rate stays.
        Livewire::test(CorporateRates::class)
            ->set('company', (string) $this->turbo->id)
            ->assertSet("rates.{$option}.sedan", '10.5')
            ->set("rates.{$option}.sedan", '')
            ->call('save');

        $this->assertFalse(PricingCorporateRate::query()->where('customer_id', $this->turbo->id)->exists());
        $this->assertTrue(PricingCorporateRate::query()->whereNull('customer_id')->exists());
    }

    public function test_corporate_rates_never_reach_the_website(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $version = PricingVersion::current();
        $before = json_encode(app(PricingPayload::class)->build());

        Livewire::test(CorporateRates::class)
            ->set("rates.{$this->zoneMain->id}.sedan", '1')
            ->call('save');

        $this->assertSame($version, PricingVersion::current());
        $this->assertSame($before, json_encode(app(PricingPayload::class)->build()));
    }

    public function test_the_page_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(CorporateRates::class)->assertForbidden();
    }

    public function test_a_real_page_load_renders(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->get('/corporate-rates')->assertOk()->assertSee('Corporate rates');
    }
}
