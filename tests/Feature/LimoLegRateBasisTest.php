<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Tests\TestCase;

/**
 * A rate per hour or per day needs the quantity it is multiplied by.
 *
 * The hours and days boxes only ever appeared on a CHAUFFEUR leg, but the rate
 * basis is offered on every leg. Pricing an ordinary transfer "per hour" left
 * the multiplier with nowhere to be entered, so the leg was saved at 0.00 BD
 * however large the rate — silently, with a real booking on the queue owing
 * nothing. Reported from the live site as "calculation is not working".
 */
final class LimoLegRateBasisTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function transfer(): \Livewire\Features\SupportTesting\Testable
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg']);

        return Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('pax_name', 'Helen')
            ->set('requested_by', 'Office')
            ->set('legs.0.from_location', 'Sanabis')
            ->set('legs.0.to_location', 'Bahrain Airport')
            ->set('legs.0.start_at', now()->addDays(2)->format('Y-m-d\TH:i'))
            ->set('legs.0.car_details', 'Sedan');
    }

    public function test_a_transfer_priced_per_hour_multiplies_by_its_hours(): void
    {
        $this->transfer()
            ->set('legs.0.rate', '12')
            ->set('legs.0.rate_basis', LimoLeg::BASIS_HOUR)
            ->set('legs.0.hours', '3')
            ->call('save')
            ->assertHasNoErrors();

        $leg = LimoLeg::query()->firstOrFail();

        $this->assertSame(3.0, $leg->hours);
        $this->assertSame(36.0, $leg->net_amount, '12 BD an hour for 3 hours is 36, not nothing.');
    }

    public function test_a_transfer_priced_per_hour_is_refused_without_its_hours(): void
    {
        $this->transfer()
            ->set('legs.0.rate', '12')
            ->set('legs.0.rate_basis', LimoLeg::BASIS_HOUR)
            ->call('save')
            ->assertHasErrors(['legs.0.hours']);

        // Refused outright rather than written down as a trip worth nothing.
        $this->assertSame(0, LimoLeg::query()->count());
    }

    public function test_a_transfer_priced_per_day_multiplies_by_its_days(): void
    {
        $this->transfer()
            ->set('legs.0.rate', '40')
            ->set('legs.0.rate_basis', LimoLeg::BASIS_DAY)
            ->set('legs.0.days', '2')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(80.0, LimoLeg::query()->firstOrFail()->net_amount);
    }

    /** The ordinary case: one price for the trip, no quantity to ask for. */
    public function test_a_transfer_priced_per_trip_is_the_rate_itself(): void
    {
        $this->transfer()
            ->set('legs.0.rate', '12')
            ->set('legs.0.rate_basis', LimoLeg::BASIS_TRIP)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(12.0, LimoLeg::query()->firstOrFail()->net_amount);
    }

    /** The box has to be on screen, or there is still nowhere to type it. */
    public function test_the_hours_box_appears_when_the_rate_is_per_hour(): void
    {
        $component = $this->transfer();

        $this->assertStringNotContainsString('legs.0.hours', $component->html());

        $component->set('legs.0.rate_basis', LimoLeg::BASIS_HOUR);
        $this->assertStringContainsString('legs.0.hours', $component->html());

        $component->set('legs.0.rate_basis', LimoLeg::BASIS_DAY);
        $html = $component->html();
        $this->assertStringContainsString('legs.0.days', $html);
        $this->assertStringNotContainsString('legs.0.hours', $html);
    }

    /**
     * The figure read off the screen is the one that gets saved. The form used
     * to carry its own copy of the arithmetic, which could drift from the
     * model's — and a price agreed with a customer must not be a second opinion.
     */
    public function test_the_total_on_screen_is_the_total_that_is_saved(): void
    {
        $component = $this->transfer()
            ->set('legs.0.rate', '12')
            ->set('legs.0.rate_basis', LimoLeg::BASIS_HOUR)
            ->set('legs.0.hours', '3')
            ->set('legs.0.vat', '1.8');

        $this->assertStringContainsString('37.80', $component->html());

        $component->call('save')->assertHasNoErrors();
        $this->assertSame(37.8, LimoLeg::query()->firstOrFail()->net_amount);
    }
}
