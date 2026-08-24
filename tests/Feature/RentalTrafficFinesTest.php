<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Rental\Livewire\OrderForm;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Support\RentalNotifications;
use Tests\TestCase;

/**
 * Traffic fines from the rental period often arrive after the car is back. During
 * the deposit hold a staff member records any fine — or confirms there were none —
 * so the amount can feed the deposit settlement and nothing is missed.
 */
final class RentalTrafficFinesTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');

        // Bespoke rental screens are ACL-gated; these tests are about the role
        // rules on top of that, so put the user where a granted staff account is.
        $this->grantEveryone('rental.order');
    }

    private function heldOrder(int $returnedDaysAgo = 2, float $deposit = 50): RentalOrder
    {
        return RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => 'Ali'])->id,
            'vehicle_id' => Vehicle::query()->create(['name' => 'Yaris', 'daily_rate' => 10])->id,
            'start_date' => Carbon::now()->subDays($returnedDaysAgo + 2),
            'end_date' => Carbon::now()->subDays($returnedDaysAgo),
            'rate_type' => 'daily', 'rate' => 10, 'deposit' => $deposit,
            'state' => RentalOrder::STATE_CLOSED, 'returned_at' => Carbon::now()->subDays($returnedDaysAgo),
        ]);
    }

    public function test_a_held_returned_deposit_starts_with_its_fines_check_pending(): void
    {
        $this->assertTrue($this->heldOrder()->finesCheckPending());
    }

    public function test_staff_can_record_a_traffic_fine(): void
    {
        $staff = User::factory()->create();
        $this->actingAs($staff);
        $order = $this->heldOrder();

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('openFines')
            ->assertSet('showFines', true)
            ->set('fines_amount', '15')
            ->set('fines_notes', 'Speeding ticket no. 4471')
            ->call('recordFines')
            ->assertHasNoErrors()
            ->assertSet('showFines', false);

        $order->refresh();
        $this->assertSame(15.0, $order->fines_amount);
        $this->assertSame('Speeding ticket no. 4471', $order->fines_notes);
        $this->assertNotNull($order->fines_checked_at);
        $this->assertSame($staff->id, $order->fines_checked_by_user_id);
        $this->assertFalse($order->finesCheckPending());
    }

    public function test_recording_zero_confirms_there_were_no_fines(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->heldOrder();

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('openFines')
            ->set('fines_amount', '0')
            ->call('recordFines')
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertSame(0.0, $order->fines_amount);
        $this->assertNotNull($order->fines_checked_at);
        $this->assertFalse($order->finesCheckPending());
    }

    public function test_a_negative_fine_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->heldOrder();

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('openFines')
            ->set('fines_amount', '-5')
            ->call('recordFines')
            ->assertHasErrors(['fines_amount']);

        $this->assertNull($order->fresh()?->fines_checked_at);
    }

    public function test_managers_are_reminded_to_check_recent_returns_for_fines(): void
    {
        $manager = User::factory()->create(['is_admin' => true]);
        $this->heldOrder(returnedDaysAgo: 2); // within the hold, unchecked

        $titles = array_map(
            static fn ($i): string => $i->title,
            app(RentalNotifications::class)->for($manager),
        );

        $this->assertContains('Check cars for traffic fines', $titles);
    }

    public function test_the_reminder_clears_once_the_fines_are_checked(): void
    {
        $manager = User::factory()->create(['is_admin' => true]);
        $order = $this->heldOrder(returnedDaysAgo: 2);
        $order->forceFill(['fines_checked_at' => Carbon::now(), 'fines_checked_by_user_id' => $manager->id])->save();

        $titles = array_map(
            static fn ($i): string => $i->title,
            app(RentalNotifications::class)->for($manager),
        );

        $this->assertNotContains('Check cars for traffic fines', $titles);
    }

    public function test_a_plain_staff_member_does_not_get_the_check_reminder(): void
    {
        $staff = User::factory()->create(['is_admin' => false, 'is_super_admin' => false, 'is_accountant' => false]);
        $this->heldOrder(returnedDaysAgo: 2);

        $titles = array_map(
            static fn ($i): string => $i->title,
            app(RentalNotifications::class)->for($staff),
        );

        $this->assertNotContains('Check cars for traffic fines', $titles);
    }
}
