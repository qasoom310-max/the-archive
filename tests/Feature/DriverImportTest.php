<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Modules\Rental\Http\Controllers\RentalDriverImportController;
use Modules\Rental\Models\Driver;
use Modules\Rental\Support\DriverImporter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Importing a driver CSV (an export from a previous system) loads the shared
 * driver store — the same people who drive for both Rent A Car and Limousine
 * — via one importer and one endpoint reached from either app's page.
 */
final class DriverImportTest extends TestCase
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
        $path = tempnam(sys_get_temp_dir(), 'drv') . '.csv';
        file_put_contents($path, "Name,CPR,License No,License Expiry,Phone,Nationality\n" . $body);

        return $path;
    }

    public function test_it_imports_a_driver_with_every_field(): void
    {
        $path = $this->csv("Hassan Ali,921000448,DL-5521,2027-03-01,38467744,Bahraini\n");

        $result = app(DriverImporter::class)->import($path);

        $this->assertSame(1, $result['imported']);

        $driver = Driver::query()->where('name', 'Hassan Ali')->sole();
        $this->assertSame('921000448', $driver->cpr);
        $this->assertSame('DL-5521', $driver->license_no);
        $this->assertSame('2027-03-01', $driver->license_expiry?->format('Y-m-d'));
        $this->assertSame('38467744', $driver->phone);
        $this->assertSame('Bahraini', $driver->nationality);
        $this->assertTrue($driver->active);
    }

    public function test_an_unparseable_expiry_date_is_dropped_rather_than_imported_wrong(): void
    {
        $path = $this->csv("Omar,921000449,DL-5522,not a date,38467745,Bahraini\n");

        app(DriverImporter::class)->import($path);

        $this->assertNull(Driver::query()->where('name', 'Omar')->sole()->license_expiry);
    }

    public function test_it_skips_duplicates_by_cpr_then_license_then_phone(): void
    {
        Driver::query()->create(['name' => 'Existing', 'cpr' => '111', 'license_no' => 'DL-1', 'phone' => '500']);

        $path = $this->csv(
            "New One,222,DL-2,,38467744,\n" .            // new
            "Dup CPR,111,DL-9,,39000000,\n" .             // dup of existing by CPR
            "Same As New,222,DL-3,,40000000,\n" .         // dup of "New One" by CPR (within file)
            "Dup License,,DL-1,,41000000,\n" .            // no CPR; dup of existing by licence
            "Phone Dup,,,,500,\n"                         // no CPR/licence; dup of existing by phone
        );

        $result = app(DriverImporter::class)->import($path);

        $this->assertSame(1, $result['imported']);   // only "New One"
        $this->assertSame(4, $result['skipped']);
        $this->assertSame(2, Driver::query()->count()); // existing + the one new
    }

    public function test_the_endpoint_redirects_back_to_whichever_driver_list_it_came_from(): void
    {
        $file = new UploadedFile($this->csv("X,9,DL-9,,9,\n"), 'd.csv', 'text/csv', null, true);
        $controller = new RentalDriverImportController();

        $fromLimo = Request::create('/app/rental/driver/import', 'POST', ['redirect' => '/app/limousine/driver'], [], ['file' => $file]);
        $this->assertSame(url('/app/limousine/driver'), $controller($fromLimo, app(DriverImporter::class))->getTargetUrl());
    }

    public function test_an_unrecognised_redirect_target_falls_back_to_the_rental_list(): void
    {
        $file = new UploadedFile($this->csv("X,9,DL-9,,9,\n"), 'd.csv', 'text/csv', null, true);
        $controller = new RentalDriverImportController();

        $request = Request::create('/app/rental/driver/import', 'POST', ['redirect' => 'https://evil.example/'], [], ['file' => $file]);
        $this->assertSame(url('/app/rental/driver'), $controller($request, app(DriverImporter::class))->getTargetUrl());
    }

    public function test_the_import_route_is_manager_gated(): void
    {
        $this->actingAs(User::factory()->create()); // plain staff
        $controller = new RentalDriverImportController();

        $file = new UploadedFile($this->csv("X,9,DL-9,,9,\n"), 'd.csv', 'text/csv', null, true);
        $request = Request::create('/app/rental/driver/import', 'POST', [], [], ['file' => $file]);

        try {
            $controller($request, app(DriverImporter::class));
            $this->fail('A non-manager should be forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, Driver::query()->count());
    }
}
