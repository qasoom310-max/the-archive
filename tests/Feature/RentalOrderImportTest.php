<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Rental\Http\Controllers\RentalOrderImportController;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Support\OrderImporter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Importing an orders CSV (an export from a previous system, the same shape
 * this screen's own export already prints) — a rental order carries its own
 * billing figures directly, so a row lands as a complete historical record
 * with no separate invoice document needed.
 */
final class RentalOrderImportTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ord') . '.csv';
        file_put_contents($path, "Reference,Customer,Car,Pick-up,Return,Total,Received,Status,Payment\n" . $body);

        return $path;
    }

    public function test_a_fully_paid_historical_order_lands_settled(): void
    {
        $path = $this->csv("RO/00099,Qassim,Eco Sport,2026-06-10,2026-06-12,50,50,Closed,Paid\n");

        $result = app(OrderImporter::class)->import($path);
        $this->assertSame(1, $result['imported']);

        $order = RentalOrder::query()->firstOrFail();
        $this->assertSame('Qassim', $order->customer->name);
        $this->assertEqualsWithDelta(50.0, $order->total, 0.001);
        $this->assertEqualsWithDelta(50.0, $order->advance_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, $order->balance, 0.001);
        $this->assertSame(RentalOrder::STATE_CLOSED, $order->state);
        $this->assertSame(RentalOrder::PAYMENT_PAID, $order->payment_status);
    }

    public function test_a_matching_fleet_vehicle_is_linked_by_name(): void
    {
        $car = Vehicle::query()->create(['name' => 'Toyota Yaris', 'daily_rate' => 10]);
        $path = $this->csv("RO/00100,Qassim,Yaris,2026-06-10,,20,0,Active,Unpaid\n");

        app(OrderImporter::class)->import($path);

        $this->assertSame($car->id, RentalOrder::query()->firstOrFail()->vehicle_id);
    }

    public function test_an_unmatched_car_name_leaves_the_order_car_less_rather_than_inventing_a_fleet_entry(): void
    {
        $path = $this->csv("RO/00101,Qassim,Some Old Car,2026-06-10,,20,0,Active,Unpaid\n");

        app(OrderImporter::class)->import($path);

        $this->assertNull(RentalOrder::query()->firstOrFail()->vehicle_id);
        $this->assertSame(0, Vehicle::query()->count());
    }

    public function test_a_part_paid_order_lands_partial(): void
    {
        $path = $this->csv("RO/00102,Qassim,,2026-06-10,,100,40,Closed,Partial\n");

        app(OrderImporter::class)->import($path);

        $order = RentalOrder::query()->firstOrFail();
        $this->assertSame(RentalOrder::PAYMENT_PARTIAL, $order->payment_status);
        $this->assertEqualsWithDelta(60.0, $order->balance, 0.001);
    }

    public function test_re_importing_the_same_file_does_not_duplicate_the_order(): void
    {
        app(OrderImporter::class)->import($this->csv("RO/00099,Qassim,,2026-06-10,,50,50,Closed,Paid\n"));
        $second = app(OrderImporter::class)->import($this->csv("RO/00099,Qassim,,2026-06-10,,50,50,Closed,Paid\n"));

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, RentalOrder::query()->count());
    }

    public function test_an_existing_customer_is_reused_not_duplicated(): void
    {
        $existing = RentalCustomer::query()->create(['name' => 'Qassim', 'phone' => '39000001']);
        app(OrderImporter::class)->import($this->csv("RO/00099,Qassim,,2026-06-10,,50,0,Active,Unpaid\n"));

        $this->assertSame(1, RentalCustomer::query()->count());
        $this->assertSame($existing->id, RentalOrder::query()->firstOrFail()->customer_id);
    }

    public function test_the_import_route_is_manager_gated(): void
    {
        $this->actingAs(User::factory()->create()); // plain staff
        $controller = new RentalOrderImportController();

        $file = new \Illuminate\Http\UploadedFile($this->csv("X,X,,2026-06-10,,50,0,Active,Unpaid\n"), 'o.csv', 'text/csv', null, true);
        $request = \Illuminate\Http\Request::create('/app/rental/order/import', 'POST', [], [], ['file' => $file]);

        try {
            $controller($request, app(OrderImporter::class));
            $this->fail('A non-manager should be forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, RentalOrder::query()->count());
    }
}
