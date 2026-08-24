<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Rental\Livewire\OrderForm;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * Per-record audit trail: who did what on a record, in order. Each bespoke
 * action logs against the record's polymorphic subject, and the record page
 * renders the trail.
 */
final class RecordActivityTrailTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');

        // The rental screens are ACL-gated; this test is about the ACTIVITY TRAIL,
        // so put its accountant where a granted staff account would be.
        $this->grantEveryone('rental.order');
    }

    private function newOrder(User $actor): RentalOrder
    {
        $this->actingAs($actor);
        $customer = RentalCustomer::query()->create(['name' => 'Client']);
        $car = Vehicle::query()->create(['name' => 'Eco Sport', 'daily_rate' => 10]);

        Livewire::test(OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $car->id)
            ->set('start_date', now()->addDay()->format('Y-m-d'))
            ->set('end_date', now()->addDays(2)->format('Y-m-d'))
            ->set('rate', '10')
            ->set('hired_time', '10:00')
            ->call('save')
            ->assertHasNoErrors();

        return RentalOrder::query()->latest('id')->firstOrFail();
    }

    public function test_creating_an_order_logs_who_did_it_against_that_record(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'name' => 'Qassim']);
        $order = $this->newOrder($user);

        $log = ActivityLog::query()
            ->where('subject_type', $order->getMorphClass())
            ->where('subject_id', $order->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Qassim', $log?->user_name);
    }

    public function test_a_payment_confirmation_is_recorded_with_the_actor(): void
    {
        $creator = User::factory()->create(['is_admin' => true, 'name' => 'Clerk']);
        $order = $this->newOrder($creator);
        $order->forceFill(['advance_amount' => $order->total])->save();
        $order->recalcTotals();
        $order->save();

        $accountant = User::factory()->create(['is_accountant' => true, 'name' => 'Mariam']);
        $this->actingAs($accountant);
        Livewire::test(OrderForm::class, ['id' => $order->id])->call('confirmPayment');

        $log = ActivityLog::query()
            ->where('subject_id', $order->id)
            ->where('action', 'payment_confirmed')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Mariam', $log?->user_name);
    }

    public function test_the_order_page_renders_the_activity_trail(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'name' => 'Qassim']);
        $order = $this->newOrder($user);

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->assertSee('Activity')
            ->assertSee('Qassim');
    }
}
