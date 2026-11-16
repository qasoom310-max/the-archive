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
use Modules\Rental\Models\RentalReceipt;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * Correcting what was typed wrongly when a car came back.
 *
 * On 9 Nov 2026 an odometer reading typed into the fuel-charge box left a
 * closed order owing 121,923 BD for petrol, and nobody could change it. The
 * owner can now correct a closed order's return figures; the order, its
 * invoice and the car's odometer follow, and the desk can no longer type a
 * fuel charge that size in the first place.
 */
final class RentalReturnCorrectionTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');
    }

    private function owner(): User
    {
        return User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
    }

    /** A closed order, 7 days at 10, with the KM typed into the fuel charge. */
    private function mistakenOrder(float $advance = 0.0): RentalOrder
    {
        $car = Vehicle::query()->create(['name' => 'Kia', 'plate_no' => '111', 'daily_rate' => 10, 'active' => true, 'odometer' => 122015]);
        $customer = RentalCustomer::query()->create(['name' => 'Renter', 'phone' => '39000001']);

        $order = RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $car->id,
            'start_date' => '2026-11-02', 'end_date' => '2026-11-09',
            'rate_type' => 'daily', 'rate' => 10, 'advance_amount' => $advance,
            'state' => RentalOrder::STATE_CLOSED,
        ]);
        $order->forceFill([
            'handover_km' => 122000, 'handover_fuel' => 'full',
            'returned_at' => '2026-11-09 22:19:00', 'return_km' => 122015, 'return_fuel' => 'empty',
            'fuel_charge' => 121922,
        ]);
        $order->recalcTotals();
        $order->save();

        return $order->refresh();
    }

    public function test_the_owner_corrects_the_fuel_charge_and_the_order_is_re_priced(): void
    {
        $this->actingAs($this->owner());
        $order = $this->mistakenOrder();
        $rental = round($order->total - 121923, 3);   // what the hire itself comes to
        $this->assertGreaterThan(0, $rental);

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->assertSee('Correct figures')
            ->call('openReturnCorrection')
            ->assertSet('showCorrection', true)
            ->assertSet('fix.fuel_charge', '121922')
            ->set('fix.fuel_charge', '12')
            ->set('fix.return_fuel', 'half')
            ->set('fix.reason', 'KM typed in the fuel charge box')
            ->call('saveReturnCorrection')
            ->assertHasNoErrors()
            ->assertSet('showCorrection', false);

        $order->refresh();
        $this->assertSame(12.0, $order->fuel_charge);
        $this->assertSame('half', $order->return_fuel);
        $this->assertSame(round($rental + 13, 3), $order->total);     // 12 + the 1.000 service fee
        $this->assertSame($order->total, $order->balance);
        $this->assertSame(RentalOrder::STATE_CLOSED, $order->state);   // still closed

        $log = ActivityLog::query()->where('action', 'return_corrected')->sole();
        $this->assertStringContainsString('121,922', (string) $log->description);
        $this->assertStringContainsString('KM typed in the fuel charge box', (string) $log->description);
    }

    public function test_a_reason_is_required_and_nothing_changes_without_one(): void
    {
        $this->actingAs($this->owner());
        $order = $this->mistakenOrder();

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('openReturnCorrection')
            ->set('fix.fuel_charge', '12')
            ->call('saveReturnCorrection')
            ->assertHasErrors('fix.reason');

        $this->assertSame(121922.0, $order->refresh()->fuel_charge);
    }

    public function test_only_the_owner_may_correct(): void
    {
        $order = $this->mistakenOrder();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false]));

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->assertDontSee('Correct figures')
            ->call('openReturnCorrection')
            ->assertForbidden();

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->set('fix', ['return_km' => '122015', 'return_fuel' => 'full', 'fuel_charge' => '0', 'extra_charge' => '0', 'extra_charge_note' => '', 'reason' => 'trying it'])
            ->call('saveReturnCorrection')
            ->assertForbidden();

        $this->assertSame(121922.0, $order->refresh()->fuel_charge);
    }

    /** An invoice already raised from the order takes the corrected total. */
    public function test_an_invoice_already_raised_follows_the_correction(): void
    {
        $this->actingAs($this->owner());
        $order = $this->mistakenOrder();
        $invoice = $order->createInvoice();
        $this->assertSame($order->total, $invoice->total);
        $this->assertStringContainsString('121,923', (string) $invoice->notes);

        // The customer had paid the hire itself.
        $hire = round($order->total - 121923, 3);
        RentalReceipt::query()->create(['invoice_id' => $invoice->id, 'customer_id' => $order->customer_id, 'date' => '2026-11-09', 'amount' => $hire, 'method' => 'cash']);
        $this->assertSame('partial', $invoice->refresh()->status);

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('openReturnCorrection')
            ->set('fix.fuel_charge', '0')
            ->set('fix.reason', 'no fuel was owed')
            ->call('saveReturnCorrection')
            ->assertHasNoErrors();

        $invoice->refresh();
        $this->assertSame($hire, $invoice->total);
        $this->assertSame('paid', $invoice->status);                 // paid in full against the real total
        $this->assertStringNotContainsString('121,923', (string) $invoice->notes);
        $this->assertSame($hire, $invoice->amount_paid);             // the money received is untouched
    }

    public function test_the_cars_odometer_follows_a_corrected_km_unless_the_car_moved_on(): void
    {
        $this->actingAs($this->owner());
        $order = $this->mistakenOrder();

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('openReturnCorrection')
            ->set('fix.return_km', '122650')
            ->set('fix.fuel_charge', '0')   // the mistaken charge would be refused
            ->set('fix.reason', 'KM read wrongly')
            ->call('saveReturnCorrection')
            ->assertHasNoErrors();

        $this->assertSame(122650, $order->refresh()->return_km);
        $this->assertSame(122650, $order->vehicle?->refresh()->odometer);

        // The car has been out again since: its newer reading is left alone.
        $order->vehicle?->update(['odometer' => 130000]);
        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('openReturnCorrection')
            ->set('fix.return_km', '122700')
            ->set('fix.reason', 'KM read wrongly again')
            ->call('saveReturnCorrection');

        $this->assertSame(130000, $order->vehicle?->refresh()->odometer);
    }

    public function test_a_return_km_below_the_handover_is_refused(): void
    {
        $this->actingAs($this->owner());
        $order = $this->mistakenOrder();

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('openReturnCorrection')
            ->set('fix.return_km', '100')
            ->set('fix.reason', 'testing the floor')
            ->call('saveReturnCorrection')
            ->assertHasErrors('fix.return_km');
    }

    /** The slip itself is stopped at the door: nobody can type a fuel charge that size. */
    public function test_a_fuel_charge_the_size_of_an_odometer_is_refused(): void
    {
        $this->actingAs($this->owner());
        $order = $this->mistakenOrder();

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('openReturnCorrection')
            ->set('fix.fuel_charge', '122015')
            ->set('fix.reason', 'typed the KM again')
            ->call('saveReturnCorrection')
            ->assertHasErrors('fix.fuel_charge');

        // And at the desk, when the car is returned.
        $order->forceFill(['state' => RentalOrder::STATE_ACTIVE, 'returned_at' => null, 'fuel_charge' => 0])->save();
        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('closeRental')
            ->set('return_km', '122015')
            ->set('fuel_charge', '122015')
            ->call('confirmReturn')
            ->assertHasErrors('fuel_charge');

        $this->assertSame(RentalOrder::STATE_ACTIVE, $order->refresh()->state);
    }

    public function test_an_order_still_out_cannot_be_corrected(): void
    {
        $this->actingAs($this->owner());
        $order = $this->mistakenOrder();
        $order->forceFill(['state' => RentalOrder::STATE_ACTIVE, 'returned_at' => null])->save();

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('openReturnCorrection')
            ->assertSet('showCorrection', false);
    }
}
