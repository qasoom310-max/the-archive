<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Customers as LimoCustomers;
use Modules\Limousine\Livewire\Drivers as LimoDrivers;
use Modules\Rental\Livewire\Drivers as RentalDrivers;
use Tests\TestCase;

/**
 * The Import button now shows on every Customer/Driver list, not just Rent A
 * Car's — Customer and Driver are one shared store, so both apps' pages
 * offer the same door in.
 */
final class CustomerDriverImportButtonTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');
        app(ModuleManager::class)->install('limousine');
    }

    public function test_a_manager_sees_import_on_limousine_customers(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(LimoCustomers::class)
            ->assertSeeHtml(url('/app/rental/customer/import'))
            ->assertSee(__('Import'));
    }

    public function test_a_manager_sees_import_on_limousine_drivers(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(LimoDrivers::class)
            ->assertSeeHtml(url('/app/rental/driver/import'))
            ->assertSee(__('Import'));
    }

    public function test_a_manager_sees_import_on_rental_drivers(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(RentalDrivers::class)
            ->assertSeeHtml(url('/app/rental/driver/import'));
    }

    public function test_a_non_manager_does_not_see_import_anywhere(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(LimoCustomers::class)->assertDontSeeHtml(url('/app/rental/customer/import'));
        Livewire::test(LimoDrivers::class)->assertDontSeeHtml(url('/app/rental/driver/import'));
        Livewire::test(RentalDrivers::class)->assertDontSeeHtml(url('/app/rental/driver/import'));
    }
}
