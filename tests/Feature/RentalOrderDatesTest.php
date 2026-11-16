<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Rental\Livewire\OrderForm;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * Booking date rules: the return date must be a later day than pick-up, and a
 * pick-up date in the past is only allowed for admins / super-admins.
 */
final class RentalOrderDatesTest extends TestCase
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

    /** @return array{0: RentalCustomer, 1: Vehicle} */
    private function bookingTargets(): array
    {
        return [
            RentalCustomer::query()->create(['name' => 'Ali']),
            Vehicle::query()->create(['name' => 'Yaris', 'daily_rate' => 10]),
        ];
    }

    public function test_non_admin_cannot_book_a_past_pickup_date(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        [$customer, $vehicle] = $this->bookingTargets();

        Livewire::test(OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)
            ->set('start_date', now()->subDay()->toDateString())
            ->set('end_date', now()->addDay()->toDateString())
            ->set('hired_time', '10:00')
            ->call('save')
            ->assertHasErrors(['start_date']);

        $this->assertSame(0, RentalOrder::query()->count());
    }

    /**
     * Backdating is for the super admin, the accountant and a user granted it
     * by name. A plain admin lost it on 2026-11-16 (the owner's call): a
     * backdated hire moves money between months.
     */
    public function test_only_the_owner_the_accountant_and_a_granted_user_can_backdate(): void
    {
        $may = [
            'super admin' => ['is_admin' => true, 'is_super_admin' => true],
            'accountant' => ['is_admin' => true, 'is_accountant' => true],
            'granted by name' => ['is_admin' => true, 'can_backdate_rentals' => true],
        ];
        $mayNot = [
            'plain admin' => ['is_admin' => true],
        ];

        foreach ($may + $mayNot as $who => $attributes) {
            RentalOrder::query()->delete();
            $this->actingAs(User::factory()->create($attributes));
            [$customer, $vehicle] = $this->bookingTargets();

            $form = Livewire::test(OrderForm::class)
                ->set('customer_id', $customer->id)
                ->set('vehicle_id', $vehicle->id)
                ->set('start_date', now()->subDays(3)->toDateString())
                ->set('end_date', now()->addDay()->toDateString())
                ->set('hired_time', '10:00')
                ->call('save');

            if (isset($may[$who])) {
                $form->assertHasNoErrors();
                $this->assertSame(1, RentalOrder::query()->count(), $who);
            } else {
                $form->assertHasErrors(['start_date']);
                $this->assertSame(0, RentalOrder::query()->count(), $who);
            }
        }
    }

    /**
     * Keeping a date is not backdating. An order that began last week must
     * still be editable by ordinary staff today; only MOVING the pick-up into
     * the past is held back.
     */
    public function test_an_order_that_already_began_can_still_be_edited_by_staff(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        [$customer, $vehicle] = $this->bookingTargets();

        $began = now()->subDays(5)->toDateString();
        $order = RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'start_date' => $began, 'end_date' => now()->addDays(2)->toDateString(),
            'hired_time' => '10:00', 'rate_type' => 'daily', 'rate' => 10,
        ]);

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->set('notes', 'customer asked for a child seat')
            ->call('save')
            ->assertHasNoErrors(['start_date']);

        // Moving it further into the past is still refused.
        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->set('start_date', now()->subDays(9)->toDateString())
            ->call('save')
            ->assertHasErrors(['start_date']);

        $this->assertSame($began, $order->refresh()->start_date->toDateString());
    }

    public function test_the_migration_grants_the_via_account_only(): void
    {
        $via = User::factory()->create(['name' => 'via', 'email' => 'someone@wanaan-bh.com']);
        $viaMail = User::factory()->create(['name' => 'Office', 'email' => 'via@wanaan-bh.com']);
        $other = User::factory()->create(['name' => 'Olivia', 'email' => 'olivia@wanaan-bh.com']);

        $migration = require base_path('database/migrations/2026_11_16_100001_add_can_backdate_rentals_to_users.php');
        $migration->up();

        $this->assertTrue($via->refresh()->canBackdateRentals());
        $this->assertTrue($viaMail->refresh()->canBackdateRentals());
        $this->assertFalse($other->refresh()->canBackdateRentals());
    }

    public function test_return_date_must_be_after_pickup(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        [$customer, $vehicle] = $this->bookingTargets();

        // Same day → rejected (return must be a LATER day).
        Livewire::test(OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)
            ->set('start_date', now()->addDay()->toDateString())
            ->set('end_date', now()->addDay()->toDateString())
            ->set('hired_time', '10:00')
            ->call('save')
            ->assertHasErrors(['end_date']);

        // Return before pick-up → rejected.
        Livewire::test(OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)
            ->set('start_date', now()->addDays(2)->toDateString())
            ->set('end_date', now()->addDay()->toDateString())
            ->set('hired_time', '10:00')
            ->call('save')
            ->assertHasErrors(['end_date']);

        $this->assertSame(0, RentalOrder::query()->count());
    }

    public function test_a_valid_future_range_saves_for_anyone(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        [$customer, $vehicle] = $this->bookingTargets();

        Livewire::test(OrderForm::class)
            ->set('customer_id', $customer->id)
            ->set('vehicle_id', $vehicle->id)
            ->set('start_date', now()->addDay()->toDateString())
            ->set('end_date', now()->addDays(3)->toDateString())
            ->set('hired_time', '10:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, RentalOrder::query()->count());
    }
}
