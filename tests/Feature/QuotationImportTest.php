<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Modules\Limousine\Http\Controllers\LimoQuotationImportController;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Support\QuotationImporter as LimoQuotationImporter;
use Modules\Rental\Http\Controllers\RentalQuotationImportController;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalQuotation;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Support\QuotationImporter as RentalQuotationImporter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Importing a quotations CSV (an export from a previous system, the same
 * shape each screen's own export already prints) — a quote is a price
 * offered, not billed, so no invoice is backfilled the way Bookings/Orders
 * needed.
 */
final class QuotationImportTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function csv(string $header, string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'quo') . '.csv';
        file_put_contents($path, $header . "\n" . $body);

        return $path;
    }

    // --- Limousine -----------------------------------------------------

    public function test_limousine_quotation_imports_with_customer_and_status(): void
    {
        app(ModuleManager::class)->install('limousine');
        $path = $this->csv('Reference,Customer,Valid until,Fare,Status', "QT/00099,Dadabhai Travel,2026-12-01,80,Sent\n");

        $result = app(LimoQuotationImporter::class)->import($path);
        $this->assertSame(1, $result['imported']);

        $quote = LimoQuotation::query()->firstOrFail();
        $this->assertSame('Dadabhai Travel', $quote->customer->name);
        $this->assertEqualsWithDelta(80.0, $quote->fare, 0.001);
        $this->assertSame(LimoQuotation::STATUS_SENT, $quote->status);
    }

    public function test_limousine_quotation_reuses_an_existing_customer(): void
    {
        app(ModuleManager::class)->install('limousine');
        $existing = LimoCustomer::query()->create(['name' => 'Dadabhai Travel', 'type' => 'company']);
        app(LimoQuotationImporter::class)->import($this->csv('Reference,Customer,Valid until,Fare,Status', "QT/00099,Dadabhai Travel,,80,\n"));

        $this->assertSame(1, LimoCustomer::query()->count());
        $this->assertSame($existing->id, LimoQuotation::query()->firstOrFail()->customer_id);
    }

    public function test_limousine_quotation_re_import_does_not_duplicate(): void
    {
        app(ModuleManager::class)->install('limousine');
        app(LimoQuotationImporter::class)->import($this->csv('Reference,Customer,Valid until,Fare,Status', "QT/1,Dadabhai,2026-12-01,80,\n"));
        $second = app(LimoQuotationImporter::class)->import($this->csv('Reference,Customer,Valid until,Fare,Status', "QT/1,Dadabhai,2026-12-01,80,\n"));

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, LimoQuotation::query()->count());
    }

    public function test_limousine_quotation_import_is_manager_gated(): void
    {
        app(ModuleManager::class)->install('limousine');
        $this->actingAs(User::factory()->create());
        $controller = new LimoQuotationImportController();

        $file = new UploadedFile($this->csv('Reference,Customer,Valid until,Fare,Status', "X,X,,80,\n"), 'q.csv', 'text/csv', null, true);
        $request = Request::create('/x', 'POST', [], [], ['file' => $file]);

        try {
            $controller($request, app(LimoQuotationImporter::class));
            $this->fail('A non-manager should be forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, LimoQuotation::query()->count());
    }

    // --- Rental -----------------------------------------------------

    public function test_rental_quotation_links_a_matching_fleet_vehicle(): void
    {
        app(ModuleManager::class)->install('rental');
        $car = Vehicle::query()->create(['name' => 'Toyota Yaris', 'daily_rate' => 10]);
        app(RentalQuotationImporter::class)->import($this->csv('Reference,Customer,Car,Valid until,Total,Status', "RQ/00099,Qassim,Yaris,2026-12-01,60,Draft\n"));

        $quote = RentalQuotation::query()->firstOrFail();
        $this->assertSame($car->id, $quote->vehicle_id);
        $this->assertEqualsWithDelta(60.0, $quote->total, 0.001);
    }

    public function test_rental_quotation_reuses_an_existing_customer(): void
    {
        app(ModuleManager::class)->install('rental');
        $existing = RentalCustomer::query()->create(['name' => 'Qassim', 'phone' => '39000001']);
        app(RentalQuotationImporter::class)->import($this->csv('Reference,Customer,Car,Valid until,Total,Status', "RQ/00099,Qassim,,,60,\n"));

        $this->assertSame(1, RentalCustomer::query()->count());
        $this->assertSame($existing->id, RentalQuotation::query()->firstOrFail()->customer_id);
    }

    public function test_rental_quotation_re_import_does_not_duplicate(): void
    {
        app(ModuleManager::class)->install('rental');
        app(RentalQuotationImporter::class)->import($this->csv('Reference,Customer,Car,Valid until,Total,Status', "RQ/1,Qassim,,2026-12-01,60,\n"));
        $second = app(RentalQuotationImporter::class)->import($this->csv('Reference,Customer,Car,Valid until,Total,Status', "RQ/1,Qassim,,2026-12-01,60,\n"));

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, RentalQuotation::query()->count());
    }

    public function test_rental_quotation_import_is_manager_gated(): void
    {
        app(ModuleManager::class)->install('rental');
        $this->actingAs(User::factory()->create());
        $controller = new RentalQuotationImportController();

        $file = new UploadedFile($this->csv('Reference,Customer,Car,Valid until,Total,Status', "X,X,,,60,\n"), 'q.csv', 'text/csv', null, true);
        $request = Request::create('/x', 'POST', [], [], ['file' => $file]);

        try {
            $controller($request, app(RentalQuotationImporter::class));
            $this->fail('A non-manager should be forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, RentalQuotation::query()->count());
    }
}
