<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Modules\Rental\Http\Controllers\RentalOrderExportController;
use Modules\Rental\Livewire\Orders;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalInvoice;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Tick boxes on the Rent A Car orders list: ticked rows narrow the downloads,
 * and can be cancelled or deleted together.
 */
final class RentalOrderBulkActionsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    private function order(string $customer, string $state = RentalOrder::STATE_ACTIVE, float $advance = 0): RentalOrder
    {
        $vehicle = Vehicle::query()->create(['name' => 'Car ' . $customer, 'daily_rate' => 10]);

        return RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => $customer])->id,
            'vehicle_id' => $vehicle->id,
            'order_date' => '2026-06-01', 'start_date' => '2026-06-01', 'end_date' => '2026-06-02',
            'rate_type' => 'daily', 'rate' => 10, 'state' => $state, 'advance_amount' => $advance,
        ]);
    }

    private function streamed(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    public function test_the_csv_narrows_to_the_ticked_rows(): void
    {
        $this->order('Alpha');
        $picked = $this->order('Bravo');

        $body = $this->streamed(app(RentalOrderExportController::class)->csv(Request::create('/x', 'GET', ['ids' => (string) $picked->id])));

        $this->assertStringContainsString('Bravo', $body);
        $this->assertStringNotContainsString('Alpha', $body);
    }

    public function test_the_header_box_ticks_the_page_and_the_links_carry_the_ids(): void
    {
        $a = $this->order('Alpha');
        $b = $this->order('Bravo');

        $component = Livewire::test(Orders::class)->set('selectPage', true);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_map('intval', $component->get('selected')));
        $component->assertSee('ids=' . urlencode($b->id . ',' . $a->id), false);
    }

    public function test_changing_the_tab_drops_the_ticks(): void
    {
        $a = $this->order('Alpha');

        Livewire::test(Orders::class)
            ->set('selected', [(string) $a->id])
            ->set('tab', 'closed')
            ->assertSet('selected', []);
    }

    public function test_cancel_selected_cancels_open_orders_and_frees_the_car(): void
    {
        $a = $this->order('Alpha');
        $closed = $this->order('Bravo', RentalOrder::STATE_CLOSED);

        Livewire::test(Orders::class)
            ->set('selected', [(string) $a->id, (string) $closed->id])
            ->call('cancelSelected')
            ->assertSet('selected', []);

        $this->assertSame(RentalOrder::STATE_CANCELLED, $a->refresh()->state);
        $this->assertSame(RentalOrder::STATE_CLOSED, $closed->refresh()->state);
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $a->vehicle?->refresh()->status);
    }

    public function test_delete_selected_removes_orders_but_keeps_ones_with_money(): void
    {
        $plain = $this->order('Alpha');
        $paid = $this->order('Bravo', advance: 20);
        $invoiced = $this->order('Charlie');
        RentalInvoice::query()->create(['customer_id' => $invoiced->customer_id, 'order_id' => $invoiced->id, 'total' => 10]);

        Livewire::test(Orders::class)
            ->set('selected', [(string) $plain->id, (string) $paid->id, (string) $invoiced->id])
            ->call('deleteSelected')
            ->assertSee($paid->reference)
            ->assertSee($invoiced->reference);

        $this->assertNull(RentalOrder::query()->find($plain->id));
        $this->assertNotNull(RentalOrder::query()->find($paid->id));
        $this->assertNotNull(RentalOrder::query()->find($invoiced->id));
        $this->assertDatabaseHas('activity_logs', ['action' => 'deleted', 'subject' => 'Rental orders']);
    }

    public function test_someone_without_delete_rights_cannot_delete(): void
    {
        $order = $this->order('Alpha');
        $staff = User::factory()->create();
        $this->grantEveryone('rental.order');
        $this->actingAs($staff);

        Livewire::test(Orders::class)
            ->set('selected', [(string) $order->id])
            ->call('deleteSelected')
            ->assertForbidden();

        $this->assertNotNull(RentalOrder::query()->find($order->id));
    }
}
