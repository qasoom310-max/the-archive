<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Rental\Livewire\OrderForm;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * The security deposit is held after the car is returned and then settled — fully
 * refunded, partly deducted, or kept entirely — by an accountant / super-admin
 * only, with a reason and photos for any deduction.
 */
final class RentalDepositSettlementTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');
    }

    private function closedOrder(float $deposit = 50): RentalOrder
    {
        $order = RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => 'Ali'])->id,
            'vehicle_id' => Vehicle::query()->create(['name' => 'Yaris', 'daily_rate' => 10])->id,
            'start_date' => Carbon::now()->subDays(3),
            'end_date' => Carbon::now()->subDay(),
            'rate_type' => 'daily', 'rate' => 10, 'deposit' => $deposit,
            'state' => RentalOrder::STATE_CLOSED, 'returned_at' => Carbon::now()->subDay(),
        ]);

        return $order;
    }

    public function test_the_deposit_hold_runs_14_days_from_return(): void
    {
        $order = $this->closedOrder();
        $this->assertTrue($order->depositPending());
        $this->assertSame(
            $order->returned_at?->copy()->addDays(14)->toDateString(),
            $order->depositHoldUntil()?->toDateString(),
        );
    }

    public function test_accountant_can_refund_the_deposit_in_full(): void
    {
        $this->actingAs(User::factory()->create(['is_accountant' => true]));
        $order = $this->closedOrder(50);

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('settleDeposit')
            ->set('depositOutcome', 'refund')
            ->call('confirmDeposit')
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertSame(RentalOrder::DEPOSIT_REFUNDED, $order->deposit_status);
        $this->assertSame(0.0, $order->deposit_deducted);
        $this->assertSame(50.0, $order->depositRefundAmount());
        $this->assertNotNull($order->deposit_resolved_at);
    }

    public function test_a_partial_deduction_keeps_a_reason_and_photos(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['is_accountant' => true]));
        $order = $this->closedOrder(50);

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('settleDeposit')
            ->set('depositOutcome', 'deduct')
            ->set('deposit_deducted', '20')
            ->set('deposit_reason', 'Scratched bumper')
            ->set('depositPhotos', [UploadedFile::fake()->image('damage.jpg')])
            ->call('confirmDeposit')
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertSame(RentalOrder::DEPOSIT_PARTIAL, $order->deposit_status);
        $this->assertSame(20.0, $order->deposit_deducted);
        $this->assertSame(30.0, $order->depositRefundAmount());
        $this->assertSame('Scratched bumper', $order->deposit_reason);
        $this->assertNotEmpty($order->deposit_images);
        Storage::disk('public')->assertExists($order->deposit_images[0]);
    }

    public function test_the_deposit_can_be_forfeited_entirely(): void
    {
        $this->actingAs(User::factory()->create(['is_accountant' => true]));
        $order = $this->closedOrder(50);

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('settleDeposit')
            ->set('depositOutcome', 'forfeit')
            ->set('deposit_reason', 'Major damage, customer unreachable')
            ->call('confirmDeposit')
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertSame(RentalOrder::DEPOSIT_FORFEITED, $order->deposit_status);
        $this->assertSame(50.0, $order->deposit_deducted);
        $this->assertSame(0.0, $order->depositRefundAmount());
    }

    public function test_a_deduction_requires_a_reason(): void
    {
        $this->actingAs(User::factory()->create(['is_accountant' => true]));
        $order = $this->closedOrder(50);

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('settleDeposit')
            ->set('depositOutcome', 'deduct')
            ->set('deposit_deducted', '20')
            ->set('deposit_reason', '')
            ->call('confirmDeposit')
            ->assertHasErrors(['deposit_reason']);

        $this->assertTrue($order->fresh()?->depositPending());
    }

    public function test_a_deduction_cannot_exceed_the_deposit(): void
    {
        $this->actingAs(User::factory()->create(['is_accountant' => true]));
        $order = $this->closedOrder(50);

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('settleDeposit')
            ->set('depositOutcome', 'deduct')
            ->set('deposit_deducted', '80')   // more than the 50 deposit
            ->set('deposit_reason', 'Too much')
            ->call('confirmDeposit')
            ->assertHasErrors(['deposit_deducted']);
    }

    public function test_a_regular_admin_cannot_settle_the_deposit(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false, 'is_accountant' => false]));
        $order = $this->closedOrder(50);

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('settleDeposit')
            ->assertForbidden();

        $this->assertTrue($order->fresh()?->depositPending());
    }

    public function test_super_admin_can_settle_the_deposit(): void
    {
        $super = User::factory()->create(['is_admin' => true, 'is_super_admin' => true]);
        $this->actingAs($super);
        $order = $this->closedOrder(50);

        Livewire::test(OrderForm::class, ['id' => $order->id])
            ->call('settleDeposit')
            ->set('depositOutcome', 'refund')
            ->call('confirmDeposit');

        $this->assertSame($super->id, $order->fresh()?->deposit_resolved_by_user_id);
    }
}
