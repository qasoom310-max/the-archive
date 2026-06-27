<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Rental\Livewire\VehicleForm;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * A car carries a purchase/cost price plus the vendor invoice and original
 * agreement (chiefly for outside / rented-in cars), so the accountant can work
 * out net revenue. Set inline on the car page by an accountant or manager.
 */
final class RentalCarCostTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');
    }

    public function test_an_accountant_saves_cost_and_attached_documents(): void
    {
        $this->actingAs(User::factory()->create(['is_accountant' => true]));
        $car = Vehicle::query()->create(['name' => 'GMC Yukon']);

        Livewire::test(VehicleForm::class, ['id' => $car->id])
            ->assertSee('Cost & documents')
            ->set('purchaseInput', '350')
            ->set('invoicePath', 'rental_vehicles/inv.pdf')
            ->set('agreementPath', 'rental_vehicles/agreement.pdf')
            ->set('isOutsideInput', true)
            ->call('saveCost')
            ->assertHasNoErrors();

        $car->refresh();
        $this->assertSame(350.0, $car->purchase_price);
        $this->assertSame('rental_vehicles/inv.pdf', $car->purchase_invoice);
        $this->assertSame('rental_vehicles/agreement.pdf', $car->agreement_copy);
        $this->assertTrue($car->is_outside); // marked as a rented-in car
    }

    public function test_a_manager_can_also_save_cost(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $car = Vehicle::query()->create(['name' => 'Lexus ES350']);

        Livewire::test(VehicleForm::class, ['id' => $car->id])
            ->set('purchaseInput', '500')
            ->call('saveCost')
            ->assertHasNoErrors();

        $this->assertSame(500.0, $car->fresh()?->purchase_price);
    }

    public function test_a_plain_staff_user_cannot_see_or_save_cost(): void
    {
        $this->actingAs(User::factory()->create()); // not accountant, not manager
        $car = Vehicle::query()->create(['name' => 'Nissan Sunny']);

        Livewire::test(VehicleForm::class, ['id' => $car->id])
            ->assertDontSee('Cost & documents')
            ->set('purchaseInput', '999')
            ->call('saveCost')
            ->assertForbidden();

        $this->assertSame(0.0, $car->fresh()?->purchase_price);
    }
}
