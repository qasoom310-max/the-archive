<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Modules\Rental\Http\Controllers\RentalMaintenanceImportController;
use Modules\Rental\Http\Controllers\RentalReplacementImportController;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalMaintenance;
use Modules\Rental\Models\RentalReplacement;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Support\MaintenanceImporter;
use Modules\Rental\Support\ReplacementImporter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Importing a maintenance-records or replacements CSV (an export from a
 * previous system, the same shape each screen's own export already prints).
 * A row lands at its recorded status directly — never through
 * approve()/start()/complete() or activate(), which exist for work still
 * moving today, not a job already finished years ago.
 */
final class MaintenanceReplacementImportTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function csv(string $header, string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mtrp') . '.csv';
        file_put_contents($path, $header . "\n" . $body);

        return $path;
    }

    // --- Maintenance -----------------------------------------------------

    public function test_maintenance_imports_and_links_a_matching_fleet_vehicle(): void
    {
        app(ModuleManager::class)->install('rental');
        $car = Vehicle::query()->create(['name' => 'Toyota Yaris', 'daily_rate' => 10]);
        $path = $this->csv('Reference,Car,Type,Priority,Date,Cost,Status', "MNT/00099,Yaris,Oil change,High,2026-01-10,25.5,\n");

        $result = app(MaintenanceImporter::class)->import($path);
        $this->assertSame(1, $result['imported']);

        $record = RentalMaintenance::query()->firstOrFail();
        $this->assertSame($car->id, $record->vehicle_id);
        $this->assertSame('oil_change', $record->type);
        $this->assertSame(RentalMaintenance::PRIORITY_HIGH, $record->priority);
        $this->assertEqualsWithDelta(25.5, $record->cost, 0.001);
        // Historical rows default to Done — never routed through the live
        // approve/start/complete workflow.
        $this->assertSame(RentalMaintenance::STATUS_DONE, $record->status);
    }

    public function test_maintenance_with_no_matching_vehicle_keeps_the_label_as_a_description(): void
    {
        app(ModuleManager::class)->install('rental');
        app(MaintenanceImporter::class)->import($this->csv('Reference,Car,Type,Priority,Date,Cost,Status', "MNT/1,Unknown Car,,,,15,\n"));

        $record = RentalMaintenance::query()->firstOrFail();
        $this->assertNull($record->vehicle_id);
        $this->assertSame('Unknown Car', $record->description);
    }

    public function test_maintenance_re_import_does_not_duplicate(): void
    {
        app(ModuleManager::class)->install('rental');
        $car = Vehicle::query()->create(['name' => 'Toyota Yaris', 'daily_rate' => 10]);
        app(MaintenanceImporter::class)->import($this->csv('Reference,Car,Type,Priority,Date,Cost,Status', "M/1,Yaris,,,2026-01-10,25,\n"));
        $second = app(MaintenanceImporter::class)->import($this->csv('Reference,Car,Type,Priority,Date,Cost,Status', "M/1,Yaris,,,2026-01-10,25,\n"));

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, RentalMaintenance::query()->count());
        $this->assertNotNull($car->id);
    }

    public function test_maintenance_import_is_manager_gated(): void
    {
        app(ModuleManager::class)->install('rental');
        $this->actingAs(User::factory()->create());
        $controller = new RentalMaintenanceImportController();

        $file = new UploadedFile($this->csv('Reference,Car,Type,Priority,Date,Cost,Status', "X,X,,,,25,\n"), 'm.csv', 'text/csv', null, true);
        $request = Request::create('/x', 'POST', [], [], ['file' => $file]);

        try {
            $controller($request, app(MaintenanceImporter::class));
            $this->fail('A non-manager should be forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, RentalMaintenance::query()->count());
    }

    // --- Replacements ------------------------------------------------------

    public function test_replacement_imports_with_customer_and_matching_vehicles(): void
    {
        app(ModuleManager::class)->install('rental');
        $original = Vehicle::query()->create(['name' => 'Nissan Sunny', 'daily_rate' => 8]);
        $replacement = Vehicle::query()->create(['name' => 'Toyota Yaris', 'daily_rate' => 10]);

        $path = $this->csv(
            'Reference,Customer,Original car,Replacement car,Date,Status',
            "REP/00099,Qassim,Sunny,Yaris,2026-01-10,Active\n",
        );
        $result = app(ReplacementImporter::class)->import($path);
        $this->assertSame(1, $result['imported']);

        $record = RentalReplacement::query()->firstOrFail();
        $this->assertSame('Qassim', $record->customer->name);
        $this->assertSame($original->id, $record->original_vehicle_id);
        $this->assertSame($replacement->id, $record->replacement_vehicle_id);
        $this->assertSame(RentalReplacement::STATUS_ACTIVE, $record->status);
        $this->assertNull($record->order_id);
    }

    public function test_replacement_defaults_to_closed_when_no_status_given(): void
    {
        app(ModuleManager::class)->install('rental');
        app(ReplacementImporter::class)->import($this->csv('Reference,Customer,Original car,Replacement car,Date,Status', "REP/1,Qassim,,,2026-01-10,\n"));

        $this->assertSame(RentalReplacement::STATUS_CLOSED, RentalReplacement::query()->firstOrFail()->status);
    }

    public function test_replacement_reuses_an_existing_customer(): void
    {
        app(ModuleManager::class)->install('rental');
        $existing = RentalCustomer::query()->create(['name' => 'Qassim', 'phone' => '39000001']);
        app(ReplacementImporter::class)->import($this->csv('Reference,Customer,Original car,Replacement car,Date,Status', "REP/1,Qassim,,,,\n"));

        $this->assertSame(1, RentalCustomer::query()->count());
        $this->assertSame($existing->id, RentalReplacement::query()->firstOrFail()->customer_id);
    }

    public function test_replacement_re_import_does_not_duplicate(): void
    {
        app(ModuleManager::class)->install('rental');
        app(ReplacementImporter::class)->import($this->csv('Reference,Customer,Original car,Replacement car,Date,Status', "R/1,Qassim,,,2026-01-10,\n"));
        $second = app(ReplacementImporter::class)->import($this->csv('Reference,Customer,Original car,Replacement car,Date,Status', "R/1,Qassim,,,2026-01-10,\n"));

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, RentalReplacement::query()->count());
    }

    public function test_replacement_import_is_manager_gated(): void
    {
        app(ModuleManager::class)->install('rental');
        $this->actingAs(User::factory()->create());
        $controller = new RentalReplacementImportController();

        $file = new UploadedFile($this->csv('Reference,Customer,Original car,Replacement car,Date,Status', "X,X,,,,\n"), 'r.csv', 'text/csv', null, true);
        $request = Request::create('/x', 'POST', [], [], ['file' => $file]);

        try {
            $controller($request, app(ReplacementImporter::class));
            $this->fail('A non-manager should be forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, RentalReplacement::query()->count());
    }
}
