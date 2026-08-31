<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Modules\Limousine\Models\LimoBooking;
use Modules\Rental\Livewire\CustomerForm;
use Modules\Rental\Models\RentalCustomer;
use Tests\TestCase;

/**
 * One customer page, reached from either app.
 *
 * The same person hires a car on Monday and books a trip on Tuesday. They were
 * always one record — both apps read `rental_customers` — but Limousine opened
 * a thinner page of its own, so the summary of what the customer is worth
 * existed on one side only.
 *
 * The permission key travels with the route rather than being hard-coded, so a
 * user granted Limousine customers and not Rent A Car ones keeps exactly the
 * access they had.
 */
final class SharedCustomerPageTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
        app(ModuleManager::class)->install('rental');

        Route::middleware('web')->group(base_path('Modules/Limousine/routes/web.php'));
        Route::middleware('web')->group(base_path('Modules/Rental/routes/web.php'));
        Route::getRoutes()->refreshNameLookups();
    }

    private function customer(): RentalCustomer
    {
        return RentalCustomer::query()->create([
            'name' => 'ABBAS HAMDAN',
            'phone' => '0097339991807',
            'cpr' => '900206640',
        ]);
    }

    public function test_the_limousine_customer_route_opens_the_shared_page(): void
    {
        $customer = $this->customer();

        $this->get('/app/limousine/customer/' . $customer->id)
            ->assertOk()
            ->assertSeeLivewire(CustomerForm::class);
    }

    /** The summary the Rent A Car page had is now on both. */
    public function test_the_shared_page_shows_the_cross_business_summary(): void
    {
        $customer = $this->customer();

        LimoBooking::query()->create([
            'reference' => 'BK/00002',
            'customer_id' => $customer->id,
            'pickup_at' => now(),
            'status' => LimoBooking::STATUS_COMPLETED,
            'fare' => 50,
        ]);

        Livewire::test(CustomerForm::class, [
            'id' => $customer->id,
            'modelKey' => 'limousine.customer',
        ])
            ->assertSee('Rentals')
            ->assertSee('Limousine trips')
            ->assertSee('Total spend')
            ->assertSee('BK/00002');
    }

    /** Opened from Limousine, it is Limousine's permission that governs. */
    public function test_the_permission_key_follows_the_route(): void
    {
        $customer = $this->customer();

        Livewire::test(CustomerForm::class, ['id' => $customer->id, 'modelKey' => 'limousine.customer'])
            ->assertSet('modelKey', 'limousine.customer')
            ->assertSet('indexUrl', '/app/limousine/customer');

        Livewire::test(CustomerForm::class, ['id' => $customer->id])
            ->assertSet('modelKey', 'rental.customer')
            ->assertSet('indexUrl', '/app/rental/customer');
    }

    /** A key nobody offered falls back rather than being trusted. */
    public function test_an_unknown_key_falls_back_to_rent_a_car(): void
    {
        $customer = $this->customer();

        Livewire::test(CustomerForm::class, ['id' => $customer->id, 'modelKey' => 'anything.else'])
            ->assertSet('modelKey', 'rental.customer');
    }

    /** Both doors reach the same row — editing from one shows in the other. */
    public function test_it_is_one_record_behind_both_routes(): void
    {
        $customer = $this->customer();

        Livewire::test(CustomerForm::class, ['id' => $customer->id, 'modelKey' => 'limousine.customer'])
            ->set('phone', '39000000')
            ->call('save');

        $this->assertSame('39000000', $customer->fresh()?->phone);
        $this->assertSame(1, RentalCustomer::query()->count());
    }
}
