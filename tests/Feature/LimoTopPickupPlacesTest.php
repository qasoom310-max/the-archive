<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Locations;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoLocation;
use Modules\Limousine\Support\PickupPlaces;
use Tests\TestCase;

/**
 * The Locations page's "Most used pick-up places" box, counted from bookings.
 */
final class LimoTopPickupPlacesTest extends TestCase
{
    use DatabaseMigrations;

    private LimoBooking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');

        $customer = LimoCustomer::query()->create(['name' => 'Ahmed Ali']);
        $this->booking = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'pickup_at' => now()->addDay(), 'status' => LimoBooking::STATUS_QUEUE,
        ]);
    }

    private function trip(string $from, ?string $country = 'BH', string $status = LimoLeg::STATUS_QUEUE): void
    {
        LimoLeg::query()->create([
            'legable_type' => LimoBooking::class, 'legable_id' => $this->booking->id,
            'sequence' => LimoLeg::query()->count(), 'status' => $status, 'start_at' => now()->addDay(),
            'from_location' => $from, 'from_country' => $country, 'to_location' => 'Seef',
            'rate' => 10, 'net_amount' => 10,
        ]);
    }

    public function test_places_are_ranked_by_trips_and_cancelled_trips_do_not_count(): void
    {
        $this->trip('Bahrain Airport');
        $this->trip('Bahrain Airport');
        $this->trip('bahrain airport ');             // the same place, typed loosely
        $this->trip('Four Seasons');
        $this->trip('Four Seasons');
        $this->trip('Juffair', 'BH', LimoLeg::STATUS_CANCELLED);
        $this->trip('Juffair', 'BH', LimoLeg::STATUS_CANCELLED);
        $this->trip('Juffair', 'BH', LimoLeg::STATUS_CANCELLED);
        $this->trip('Juffair', 'BH', LimoLeg::STATUS_CANCELLED);

        $top = app(PickupPlaces::class)->top();

        $this->assertSame(['Bahrain Airport', 'Four Seasons'], array_column($top, 'name'));
        $this->assertSame([3, 2], array_column($top, 'trips'));
    }

    public function test_a_place_knows_whether_it_is_on_the_list_and_countries_stay_apart(): void
    {
        LimoLocation::query()->create(['name' => 'bahrain airport', 'country' => 'BH']);
        $this->trip('Bahrain Airport');
        $this->trip('Airport', 'SA');
        $this->trip('Airport', 'BH');

        $top = collect(app(PickupPlaces::class)->top())->keyBy('key');

        $this->assertNotNull($top['BH|bahrain airport']['location_id']);
        $this->assertNull($top['SA|airport']['location_id']);
        $this->assertSame(1, $top['SA|airport']['trips'], 'the same name in another country is another place');
        $this->assertSame(1, $top['BH|airport']['trips']);
    }

    public function test_the_box_adds_a_missing_place_once(): void
    {
        $this->trip('Four Seasons');
        $this->trip('Four Seasons');

        $page = Livewire::test(Locations::class)->assertSeeHtml('data-top-pickups')->assertSeeHtml('data-add-place');

        $page->call('addPlace', 'BH|four seasons')->assertDontSeeHtml('data-add-place');
        $page->call('addPlace', 'BH|four seasons');

        $this->assertSame(1, LimoLocation::query()->where('name', 'Four Seasons')->where('country', 'BH')->count());
    }

    public function test_only_a_place_trips_used_can_be_added_this_way(): void
    {
        $this->trip('Four Seasons');

        Livewire::test(Locations::class)->call('addPlace', 'BH|made up place');

        $this->assertSame(0, LimoLocation::query()->count());
    }

    public function test_adding_needs_the_permission_to_create_locations(): void
    {
        $this->trip('Four Seasons');
        $staff = User::factory()->create(['is_admin' => false]);
        $this->grantEveryone('limousine.location');
        \App\Models\Auth\ModelAccess::query()->where('model', 'limousine.location')->update(['perm_create' => false]);

        Livewire::actingAs($staff)->test(Locations::class)
            ->assertDontSeeHtml('data-add-place')
            ->call('addPlace', 'BH|four seasons')
            ->assertForbidden();

        $this->assertSame(0, LimoLocation::query()->count());
    }

    public function test_no_box_when_no_trip_has_a_pick_up_place(): void
    {
        Livewire::test(Locations::class)->assertDontSeeHtml('data-top-pickups');
    }
}
