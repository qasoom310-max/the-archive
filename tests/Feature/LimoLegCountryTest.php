<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Livewire\QuotationForm;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoLocation;
use Tests\TestCase;

/**
 * A trip's place is chosen country first, then the place in that country;
 * a place not on the list can be added from the trip itself.
 */
final class LimoLegCountryTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function form(): \Livewire\Features\SupportTesting\Testable
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg', 'phone' => '39000000']);

        return Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('pax_name', 'Helen Friberg')
            ->set('requested_by', 'Office')
            ->set('legs.0.start_at', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('legs.0.car_details', 'Sedan')
            ->set('legs.0.rate', '20');
    }

    public function test_a_new_leg_starts_in_bahrain_on_both_sides(): void
    {
        $leg = Livewire::test(BookingForm::class)->get('legs')[0];

        $this->assertSame('BH', $leg['from_country']);
        $this->assertSame('BH', $leg['to_country']);
    }

    public function test_only_the_gulf_countries_are_offered(): void
    {
        Livewire::test(BookingForm::class)
            ->assertViewHas('countryOptions', fn (array $options): bool => array_column($options, 'value') === ['BH', 'SA', 'AE', 'KW', 'QA', 'OM'])
            ->set('legs.0.from_location', 'Cairo')
            ->set('legs.0.from_country', 'EG')
            ->set('legs.0.to_location', 'Manama')
            ->call('save')
            ->assertHasErrors('legs.0.from_country');
    }

    public function test_the_places_are_offered_per_country(): void
    {
        LimoLocation::query()->create(['name' => 'Bahrain Airport']);
        LimoLocation::query()->create(['name' => 'Riyadh', 'country' => 'SA']);
        LimoLocation::query()->create(['name' => 'Dammam', 'country' => 'SA']);

        Livewire::test(BookingForm::class)
            ->assertViewHas('locationsByCountry', fn (array $byCountry): bool => $byCountry === [
                'BH' => ['Bahrain Airport'],
                'SA' => ['Dammam', 'Riyadh'],
            ])
            ->assertSeeHtml('id="limo-locations-SA"')
            ->set('legs.0.to_country', 'SA')
            ->assertSeeHtml('list="limo-locations-SA"');
    }

    public function test_the_countries_are_saved_on_the_trip_and_read_back(): void
    {
        $this->form()
            ->set('legs.0.from_location', 'Bahrain Airport')
            ->set('legs.0.to_country', 'SA')
            ->set('legs.0.to_location', 'Riyadh')
            ->call('save')
            ->assertHasNoErrors();

        $leg = LimoLeg::query()->sole();
        $this->assertSame('BH', $leg->from_country);
        $this->assertSame('SA', $leg->to_country);
        $this->assertSame('Riyadh', $leg->to_location);

        $reopened = Livewire::test(BookingForm::class, ['id' => LimoBooking::query()->sole()->id])->get('legs')[0];
        $this->assertSame('SA', $reopened['to_country']);
    }

    public function test_a_chauffeur_job_keeps_only_the_pick_up_country(): void
    {
        $this->form()
            ->set('legs.0.service_type', LimoLeg::TYPE_CHAUFFEUR)
            ->set('legs.0.from_country', 'SA')
            ->set('legs.0.from_location', 'Riyadh')
            ->set('legs.0.hours', '8')
            ->call('save')
            ->assertHasNoErrors();

        $leg = LimoLeg::query()->sole();
        $this->assertSame('SA', $leg->from_country);
        $this->assertNull($leg->to_country);
    }

    public function test_an_unknown_country_is_refused(): void
    {
        $this->form()
            ->set('legs.0.from_location', 'Somewhere')
            ->set('legs.0.to_location', 'Elsewhere')
            ->set('legs.0.to_country', 'ZZ')
            ->call('save')
            ->assertHasErrors('legs.0.to_country');
    }

    public function test_a_place_typed_by_hand_can_be_added_under_the_chosen_country(): void
    {
        $component = Livewire::test(QuotationForm::class)
            ->set('legs.0.to_country', 'SA')
            ->call('addLegLocation', 0, 'to', '  Al   Khobar ');

        $location = LimoLocation::query()->sole();
        $this->assertSame('Al Khobar', $location->name);
        $this->assertSame('SA', $location->country);
        $this->assertSame('Al Khobar', $component->get('legs')[0]['to_location']);

        // Adding the same place again does not make a second one.
        $component->call('addLegLocation', 0, 'to', 'al khobar');
        $this->assertSame(1, LimoLocation::query()->count());
    }

    public function test_the_same_name_can_exist_in_two_countries(): void
    {
        LimoLocation::query()->create(['name' => 'Airport']);

        Livewire::test(BookingForm::class)
            ->set('legs.0.from_country', 'SA')
            ->call('addLegLocation', 0, 'from', 'Airport');

        $this->assertSame(['BH', 'SA'], LimoLocation::query()->orderBy('country')->pluck('country')->all());
    }

    public function test_adding_a_place_needs_permission_on_locations(): void
    {
        $this->actingAs(User::factory()->create());
        $this->grantEveryone('limousine.booking');

        Livewire::test(BookingForm::class)
            ->assertViewHas('canAddLocation', false)
            ->call('addLegLocation', 0, 'from', 'New Place')
            ->assertForbidden();

        $this->assertSame(0, LimoLocation::query()->count());
    }

    public function test_changing_country_clears_a_place_of_the_old_country(): void
    {
        LimoLocation::query()->create(['name' => 'Bahrain Airport']);

        $component = Livewire::test(BookingForm::class)
            ->set('legs.0.from_location', 'Bahrain Airport')
            ->set('legs.0.from_country', 'SA');
        $this->assertSame('', $component->get('legs')[0]['from_location']);

        // A place typed by hand (not on any list) is kept.
        $component->set('legs.0.from_location', 'My hotel')->set('legs.0.from_country', 'BH');
        $this->assertSame('My hotel', $component->get('legs')[0]['from_location']);
    }

    public function test_an_older_trip_takes_its_country_from_the_saved_place(): void
    {
        LimoLocation::query()->create(['name' => 'Riyadh', 'country' => 'SA']);
        $booking = LimoBooking::query()->create(['customer_id' => LimoCustomer::query()->create(['name' => 'X'])->id]);
        $booking->legs()->create([
            'sequence' => 0, 'service_type' => LimoLeg::TYPE_TRANSFER,
            'from_location' => 'Home', 'to_location' => 'riyadh',
            'rate' => 10, 'rate_basis' => LimoLeg::BASIS_TRIP, 'days' => 1,
        ]);

        $leg = Livewire::test(BookingForm::class, ['id' => $booking->id])->get('legs')[0];

        $this->assertSame('BH', $leg['from_country']);
        $this->assertSame('SA', $leg['to_country']);
    }

    public function test_a_location_can_be_imported_with_its_country(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'loc');
        file_put_contents($path, "Name,Country\nRiyadh,Saudi Arabia\nDoha,QA\nSeef,\n");

        (new \Modules\Limousine\Support\LocationImporter())->import($path);
        @unlink($path);

        $this->assertSame(
            ['Doha' => 'QA', 'Riyadh' => 'SA', 'Seef' => 'BH'],
            LimoLocation::query()->orderBy('name')->pluck('country', 'name')->all(),
        );
    }
}
