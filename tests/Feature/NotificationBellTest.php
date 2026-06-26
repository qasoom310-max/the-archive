<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Notifications\NotificationCenter;
use App\Erp\Notifications\NotificationItem;
use App\Livewire\Navigation\NotificationBell;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Rental\Models\RentalMaintenance;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Support\RentalNotifications;
use RuntimeException;
use Tests\TestCase;

/**
 * Cross-module top-bar notification bell: a registry aggregates each module's
 * alerts for the current user, most urgent first, and the bell renders them.
 */
final class NotificationBellTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');
    }

    public function test_the_center_aggregates_providers_skips_failures_and_sorts_by_urgency(): void
    {
        $center = new NotificationCenter();
        $center->register(fn (): array => [new NotificationItem('Info one', level: 'info')]);
        $center->register(fn (): array => throw new RuntimeException('a broken provider')); // skipped, not fatal
        $center->register(fn (): array => [new NotificationItem('Critical one', level: 'critical')]);

        $items = $center->forUser(User::factory()->create());

        $this->assertCount(2, $items);
        $this->assertSame('Critical one', $items[0]->title); // critical sorts ahead of info
        $this->assertSame([], $center->forUser(null));        // guests get nothing
    }

    public function test_rental_surfaces_pending_approvals_to_a_manager_only(): void
    {
        $car = Vehicle::query()->create(['name' => 'Eco Sport', 'daily_rate' => 10, 'status' => Vehicle::STATUS_AVAILABLE]);
        RentalMaintenance::query()->create([
            'vehicle_id' => $car->id, 'type' => 'service', 'date' => now(),
            'priority' => 'critical', 'status' => RentalMaintenance::STATUS_PENDING,
        ]);

        $manager = User::factory()->create(['is_admin' => true]);
        $staff = User::factory()->create(['is_admin' => false, 'is_super_admin' => false]);

        $managerTitles = array_map(fn (NotificationItem $i): string => $i->title, app(RentalNotifications::class)->for($manager));
        $staffTitles = array_map(fn (NotificationItem $i): string => $i->title, app(RentalNotifications::class)->for($staff));

        $this->assertContains('Work orders awaiting approval', $managerTitles);
        $this->assertNotContains('Work orders awaiting approval', $staffTitles);
    }

    public function test_a_requester_gets_their_work_order_outcome(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $car = Vehicle::query()->create(['name' => 'Yaris', 'daily_rate' => 10, 'status' => Vehicle::STATUS_AVAILABLE]);
        RentalMaintenance::query()->create([
            'vehicle_id' => $car->id, 'requested_by_user_id' => $user->id, 'type' => 'service',
            'date' => now(), 'status' => RentalMaintenance::STATUS_APPROVED,
        ]);

        $titles = array_map(fn (NotificationItem $i): string => $i->title, app(RentalNotifications::class)->for($user));

        $this->assertContains('Your work order was approved', $titles);
    }

    public function test_the_bell_renders_registered_alerts(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(NotificationCenter::class)->register(fn (): array => [new NotificationItem('Test alert', 'detail', level: 'warning')]);

        Livewire::test(NotificationBell::class)
            ->assertSee('Notifications')
            ->assertSee('Test alert');
    }
}
