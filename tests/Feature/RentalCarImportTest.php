<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Support\CarImporter;
use Tests\TestCase;

/**
 * Importing a cars CSV (an export from a previous system) loads the fleet,
 * mapping the old tier → category and status, and skipping duplicate plates.
 */
final class RentalCarImportTest extends TestCase
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
        $path = tempnam(sys_get_temp_dir(), 'cars') . '.csv';
        file_put_contents($path, "Name,Reg.No,Year,Color,Type,Status\n" . $body);

        return $path;
    }

    public function test_it_maps_tier_to_category_and_status_and_keeps_plate(): void
    {
        $path = $this->csv(
            "FORD ECOSPORT,203011,2022,RED,Mid range,Available\n" .
            "BMW 7 Series,243183,2018,WHITE,Luxury,Rented Out\n" .
            "MERCEDES VITO,165391,2020,BLACK,Luxury,Maintenance\n"
        );

        $result = app(CarImporter::class)->import($path);
        $this->assertSame(3, $result['imported']);

        $eco = Vehicle::query()->where('plate_no', '203011')->sole();
        $this->assertSame('FORD ECOSPORT', $eco->name); // name kept verbatim
        $this->assertSame('sedan', $eco->category);   // Mid range → sedan
        $this->assertSame(2022, $eco->year);
        $this->assertSame('RED', $eco->color);
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $eco->status);

        $bmw = Vehicle::query()->where('plate_no', '243183')->sole();
        $this->assertSame('luxury', $bmw->category);   // Luxury → luxury
        $this->assertSame(Vehicle::STATUS_RENTED, $bmw->status); // Rented Out → rented

        $vito = Vehicle::query()->where('plate_no', '165391')->sole();
        $this->assertSame(Vehicle::STATUS_MAINTENANCE, $vito->status);
    }

    public function test_it_skips_duplicate_real_plates_but_keeps_zero_plates(): void
    {
        $path = $this->csv(
            "FORD EXPEDITION,37402,2019,BLACK,Luxury,Available\n" .
            "FORD EXPEDITION,37402,2020,BLACK,Luxury,Available\n" .   // dup plate → skipped
            "Coach Bus,0,2022,WHITE,Mid range,Available\n" .          // 0 plate → kept
            "Mini Bus,0,2020,WHITE,Small,Available\n"                 // another 0 plate → kept
        );

        $result = app(CarImporter::class)->import($path);

        $this->assertSame(3, $result['imported']);  // one expedition + two buses
        $this->assertSame(1, $result['skipped']);   // the duplicate plate
        $this->assertSame(2, Vehicle::query()->where('plate_no', '0')->count());
    }

    public function test_import_is_manager_gated(): void
    {
        $this->actingAs(User::factory()->create()); // plain staff
        $controller = new \Modules\Rental\Http\Controllers\RentalVehicleImportController();

        $file = new \Illuminate\Http\UploadedFile($this->csv("X,9,2020,WHITE,Small,Available\n"), 'c.csv', 'text/csv', null, true);
        $request = \Illuminate\Http\Request::create('/app/rental/vehicle/import', 'POST', [], [], ['file' => $file]);

        try {
            $controller($request, app(CarImporter::class));
            $this->fail('A non-manager should be forbidden.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, Vehicle::query()->count());
    }
}
