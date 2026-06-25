<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * The printable Car Hire Agreement overlay — drops the order's values onto the
 * pre-printed form, with a calibration grid.
 */
final class RentalAgreementTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    private function order(): RentalOrder
    {
        $customer = RentalCustomer::query()->create(['name' => 'Manar Mohamed', 'phone' => '+971556412563', 'license_no' => '9151974']);
        $vehicle = Vehicle::query()->create(['name' => 'Taurus', 'make' => 'Ford', 'model' => 'Taurus', 'plate_no' => '111629', 'daily_rate' => 33]);

        return RentalOrder::query()->create([
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'phone' => '+971556412563',
            'order_date' => Carbon::parse('2026-06-11'),
            'start_date' => Carbon::now()->addDay(), 'end_date' => Carbon::now()->addDays(2),
            'rate_type' => 'daily', 'rate' => 33, 'deposit' => 50,
        ]);
    }

    private function render(RentalOrder $order, bool $calibrate): string
    {
        return view('rental::agreement-print', [
            'order' => $order->load('customer', 'vehicle'),
            'calibrate' => $calibrate,
        ])->render();
    }

    public function test_agreement_renders_the_order_values(): void
    {
        $html = $this->render($this->order(), false);

        $this->assertStringContainsString('Manar Mohamed', $html); // customer name
        $this->assertStringContainsString('111629', $html);        // plate
        $this->assertStringContainsString('9151974', $html);       // driving licence
        $this->assertStringContainsString('33.000', $html);        // daily rate
        // Not calibrating → the print dialog auto-opens.
        $this->assertStringContainsString('onload="window.print()"', $html);
    }

    public function test_calibration_grid_is_opt_in(): void
    {
        $order = $this->order();

        $this->assertStringNotContainsString('Hide grid', $this->render($order, false));

        $grid = $this->render($order, true);
        $this->assertStringContainsString('Hide grid', $grid);
        // No auto-print while calibrating (so the grid can be read on screen).
        $this->assertStringNotContainsString('onload=', $grid);
    }
}
