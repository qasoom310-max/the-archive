<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Rental\Livewire\VehicleForm;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * Each car carries a monthly sales target (BHD); its page tracks this month's
 * actual earnings against it.
 */
final class RentalCarTargetTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');

        // Bespoke rental screens are ACL-gated; these tests are about the role
        // rules on top of that, so put the user where a granted staff account is.
        $this->grantEveryone('rental.vehicle');
    }

    private function orderFor(Vehicle $car, float $total, Carbon $start, string $state = RentalOrder::STATE_CLOSED): RentalOrder
    {
        return RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => 'C'])->id,
            'vehicle_id' => $car->id,
            'start_date' => $start, 'end_date' => $start->copy()->addDay(),
            'rate_type' => 'daily', 'rate' => $total, 'total' => $total, 'state' => $state,
        ]);
    }

    public function test_revenue_this_month_sums_this_months_non_cancelled_orders(): void
    {
        $car = Vehicle::query()->create(['name' => 'Eco Sport', 'monthly_target' => 500]);

        $this->orderFor($car, 100, Carbon::now()->startOfMonth()->addDays(2));        // counts
        $this->orderFor($car, 150, Carbon::now()->startOfMonth()->addDays(5));        // counts
        $this->orderFor($car, 999, Carbon::now()->subMonth());                        // other month — excluded
        $this->orderFor($car, 80, Carbon::now()->addDays(1), RentalOrder::STATE_CANCELLED); // cancelled — excluded

        $this->assertSame(250.0, $car->revenueThisMonth());
    }

    public function test_the_car_page_shows_the_target_and_progress(): void
    {
        $car = Vehicle::query()->create(['name' => 'Eco Sport', 'monthly_target' => 500]);
        $this->orderFor($car, 200, Carbon::now()->startOfMonth()->addDays(2));

        $this->assertSame(500.0, $car->fresh()?->monthly_target); // persisted?

        Livewire::test(VehicleForm::class, ['id' => $car->id])
            ->assertSee('Earnings this month vs target') // the progress card (unique to it)
            ->assertSee('200')   // earned this month
            ->assertSee('500');  // the target
    }

    public function test_a_manager_sets_the_target_inline_and_the_bar_appears(): void
    {
        $car = Vehicle::query()->create(['name' => 'Eco Sport']); // no target yet

        Livewire::test(VehicleForm::class, ['id' => $car->id])
            ->assertDontSee('Earnings this month vs target') // no bar before a target
            ->set('targetInput', '750')
            ->call('saveTarget')
            ->assertHasNoErrors()
            ->assertSee('Earnings this month vs target');     // bar now shows

        $this->assertSame(750.0, $car->fresh()?->monthly_target);
    }

    public function test_a_non_manager_cannot_set_the_target(): void
    {
        $this->actingAs(User::factory()->create()); // plain staff
        $car = Vehicle::query()->create(['name' => 'Eco Sport']);

        Livewire::test(VehicleForm::class, ['id' => $car->id])
            ->set('targetInput', '750')
            ->call('saveTarget')
            ->assertForbidden();

        $this->assertSame(0.0, $car->fresh()?->monthly_target);
    }

    public function test_no_target_card_when_target_is_zero(): void
    {
        // The form field "Monthly sales target (BHD)" is always present; only the
        // progress card (its unique subtitle) is gated on a target being set.
        $car = Vehicle::query()->create(['name' => 'No Target', 'monthly_target' => 0]);

        Livewire::test(VehicleForm::class, ['id' => $car->id])
            ->assertDontSee('Earnings this month vs target');
    }
}
