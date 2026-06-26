<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Rental\Http\Controllers\RentalSalesExportController;
use Modules\Rental\Http\Controllers\RentalSalesImportController;
use Modules\Rental\Livewire\Sales;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\RentalRevenueHistory;
use Modules\Rental\Models\Vehicle;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The Sales page rebuilds the per-car booking-revenue matrix (Expected vs actual,
 * by year) and surfaces seasonal patterns.
 */
final class RentalSalesTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
    }

    private function order(Vehicle $car, float $total, string $date, string $state = RentalOrder::STATE_CLOSED): void
    {
        RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => 'C'])->id,
            'vehicle_id' => $car->id,
            'start_date' => Carbon::parse($date), 'end_date' => Carbon::parse($date)->addDay(),
            'rate_type' => 'daily', 'rate' => $total, 'total' => $total, 'state' => $state,
        ]);
    }

    public function test_the_matrix_shows_each_cars_monthly_revenue_and_expected(): void
    {
        $car = Vehicle::query()->create(['name' => 'Eco Sport', 'plate_no' => '203011', 'monthly_target' => 100]);
        $this->order($car, 90, '2026-03-10');                                  // March
        $this->order($car, 44, '2026-04-12');                                  // April
        $this->order($car, 33, '2026-06-01');                                  // June
        $this->order($car, 500, '2026-06-15', RentalOrder::STATE_CANCELLED);   // excluded
        $this->order($car, 999, '2025-06-01');                                 // other year — excluded from 2026

        Livewire::test(Sales::class, ['year' => 2026])
            ->assertSee('203011')          // the car
            ->assertSee('1200')            // expected = target 100 × 12
            ->assertSee('90')              // March revenue
            ->assertSee('44')              // April revenue
            ->assertSee('167');            // total 90 + 44 + 33
    }

    public function test_a_cancelled_order_is_excluded_from_the_year_total(): void
    {
        $car = Vehicle::query()->create(['name' => 'Yaris', 'monthly_target' => 0]);
        $this->order($car, 100, '2026-05-01');
        $this->order($car, 250, '2026-05-02', RentalOrder::STATE_CANCELLED);

        Livewire::test(Sales::class, ['year' => 2026])
            ->assertSee('Fleet total')
            ->assertSee('100')
            ->assertDontSee('350');
    }

    public function test_not_enough_history_shows_a_friendly_seasonality_note(): void
    {
        $car = Vehicle::query()->create(['name' => 'Yaris']);
        $this->order($car, 100, '2026-05-01'); // only one month of data

        Livewire::test(Sales::class, ['year' => 2026])
            ->assertSee('Not enough history yet');
    }

    public function test_imported_history_feeds_the_matrix_and_activates_seasonality(): void
    {
        Vehicle::query()->create(['name' => 'Eco Sport', 'plate_no' => '203011', 'monthly_target' => 0]);
        foreach ([1 => 100, 2 => 250, 3 => 300] as $month => $amount) {
            RentalRevenueHistory::query()->create(['plate_no' => '203011', 'year' => 2025, 'month' => $month, 'amount' => $amount]);
        }

        Livewire::test(Sales::class, ['year' => 2025])
            ->assertSee('203011')
            ->assertSee('100')                        // January from history
            ->assertSee('650')                        // car total 100+250+300
            ->assertDontSee('Not enough history yet'); // 3 months → seasons active
    }

    public function test_the_import_controller_parses_a_csv_and_replaces_the_year(): void
    {
        // Old-system style export: extra columns (Expected/Total) are ignored.
        $csv = "Reg#,Vehicle,Expected,January,February,Total\n203011,FORD ECOSPORT,1200,100,250,350\n";
        $response = (new RentalSalesImportController())($this->importRequest(2025, $csv));

        $this->assertStringContainsString('/app/rental/sales?year=2025', $response->getTargetUrl());
        $this->assertDatabaseHas('rental_revenue_history', ['plate_no' => '203011', 'year' => 2025, 'month' => 1, 'amount' => 100]);
        $this->assertDatabaseHas('rental_revenue_history', ['plate_no' => '203011', 'year' => 2025, 'month' => 2, 'amount' => 250]);

        // Re-importing the year replaces, not appends.
        (new RentalSalesImportController())($this->importRequest(2025, "Reg#,January\n203011,999\n"));
        $this->assertDatabaseCount('rental_revenue_history', 1);
        $this->assertDatabaseHas('rental_revenue_history', ['plate_no' => '203011', 'year' => 2025, 'month' => 1, 'amount' => 999]);
    }

    public function test_a_non_manager_cannot_import(): void
    {
        $this->actingAs(User::factory()->create()); // plain staff

        try {
            (new RentalSalesImportController())($this->importRequest(2025, "Reg#,January\n203011,100\n"));
            $this->fail('A non-manager should be forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertDatabaseCount('rental_revenue_history', 0);
    }

    public function test_the_export_controller_streams_the_matrix(): void
    {
        $car = Vehicle::query()->create(['name' => 'Eco Sport', 'plate_no' => '203011', 'monthly_target' => 100]);
        $this->order($car, 90, '2026-03-10');

        $response = (new RentalSalesExportController())(Request::create('/x', 'GET', ['year' => 2026]));

        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();

        $this->assertStringContainsString('203011', $csv);
        $this->assertStringContainsString('90', $csv);
        $this->assertStringContainsString('Fleet total', $csv);
    }

    private function importRequest(int $year, string $csv): Request
    {
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.csv';
        file_put_contents($path, $csv);
        $file = new UploadedFile($path, 'history.csv', 'text/csv', null, true); // test mode

        return Request::create('/app/rental/sales/import', 'POST', ['year' => $year], [], ['file' => $file]);
    }
}
