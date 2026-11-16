<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Rental\Livewire\VehicleDocuments;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Models\VehicleDocument;
use Tests\TestCase;
use ZipArchive;

/**
 * A car's papers, kept year by year.
 *
 * The car record holds one registration card and one insurance paper, so
 * uploading 2027's replaced 2026's. The archive keeps every paper under its
 * year until the owner deletes it, and hands a year (or everything) back as a
 * ZIP.
 */
final class RentalVehicleDocumentsTest extends TestCase
{
    use DatabaseMigrations;

    private Vehicle $car;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 10:00:00');
        Storage::fake(VehicleDocument::DISK);
        Storage::fake('public');
        app(ModuleManager::class)->install('rental');
        Route::middleware('web')->group(base_path('Modules/Rental/routes/web.php'));
        Route::getRoutes()->refreshNameLookups();

        $this->car = Vehicle::query()->create(['name' => 'FORD EXPEDITION', 'plate_no' => '125818']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(): User
    {
        return User::factory()->create(['name' => 'Qassim', 'is_admin' => true, 'is_super_admin' => true]);
    }

    private function upload(int $year, string $kind, string $name): \Illuminate\Testing\TestResponse
    {
        return $this->post('/app/rental/vehicle/' . $this->car->id . '/documents', [
            'file' => UploadedFile::fake()->create($name, 120, 'application/pdf'),
            'year' => $year,
            'kind' => $kind,
        ], ['Accept' => 'application/json']);
    }

    public function test_a_new_years_paper_does_not_replace_last_years(): void
    {
        $this->actingAs($this->owner());

        $this->upload(2026, 'registration', 'reg-2026.pdf')->assertOk();
        $this->upload(2027, 'registration', 'reg-2027.pdf')->assertOk();
        $this->upload(2027, 'insurance', 'ins-2027.pdf')->assertOk();

        $this->assertSame(3, VehicleDocument::query()->count());
        $first = VehicleDocument::query()->where('year', 2026)->sole();
        $this->assertSame('reg-2026.pdf', $first->original_name);
        $this->assertSame('Qassim', $first->uploaded_by);
        // Kept on the private disk, in the car's own folder, under a random name.
        $this->assertStringStartsWith('rental_vehicle_docs/' . $this->car->id . '/', $first->path);
        Storage::disk(VehicleDocument::DISK)->assertExists($first->path);

        // The box opens on the newest year and lists each year separately.
        Livewire::test(VehicleDocuments::class, ['vehicleId' => $this->car->id])
            ->assertSet('year', 2027)
            ->assertSee('reg-2027.pdf')
            ->assertSee('ins-2027.pdf')
            ->assertDontSee('reg-2026.pdf')
            ->call('showYear', 2026)
            ->assertSee('reg-2026.pdf')
            ->assertDontSee('reg-2027.pdf');
    }

    public function test_a_paper_opens_only_for_someone_signed_in_with_access_to_cars(): void
    {
        $this->actingAs($this->owner());
        $this->upload(2026, 'insurance', 'ins.pdf')->assertOk();
        $doc = VehicleDocument::query()->sole();

        $this->get('/app/rental/vehicle-document/' . $doc->id)->assertOk();

        // Staff with no access to cars.
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get('/app/rental/vehicle-document/' . $doc->id)->assertForbidden();
        $this->upload(2026, 'insurance', 'x.pdf')->assertForbidden();

        auth()->logout();
        $this->get('/app/rental/vehicle-document/' . $doc->id)->assertRedirect('/login');
    }

    public function test_a_year_or_everything_downloads_as_a_zip(): void
    {
        $this->actingAs($this->owner());
        $this->upload(2026, 'registration', 'reg-2026.pdf');
        $this->upload(2026, 'insurance', 'ins-2026.pdf');
        $this->upload(2027, 'registration', 'reg-2027.pdf');

        $names = function (string $url): array {
            $response = $this->get($url)->assertOk();
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
            $out = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $out[] = (string) $zip->getNameIndex($i);
            }
            $zip->close();
            sort($out);

            return $out;
        };

        $this->assertSame(
            ['2026/insurance - ins-2026.pdf', '2026/registration - reg-2026.pdf'],
            $names('/app/rental/vehicle/' . $this->car->id . '/documents.zip?year=2026'),
        );
        $this->assertCount(3, $names('/app/rental/vehicle/' . $this->car->id . '/documents.zip'));

        // A year with nothing in it is not an empty ZIP.
        $this->get('/app/rental/vehicle/' . $this->car->id . '/documents.zip?year=2020')->assertNotFound();
    }

    public function test_only_the_owner_deletes_and_the_file_goes_with_the_row(): void
    {
        $this->actingAs($this->owner());
        $this->upload(2026, 'registration', 'reg-2026.pdf');
        $doc = VehicleDocument::query()->sole();

        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => false]));
        Livewire::test(VehicleDocuments::class, ['vehicleId' => $this->car->id])
            ->assertDontSee('Delete this paper for good')
            ->call('delete', $doc->id)
            ->assertForbidden();
        $this->assertSame(1, VehicleDocument::query()->count());

        $this->actingAs($this->owner());
        Livewire::test(VehicleDocuments::class, ['vehicleId' => $this->car->id])
            ->call('delete', $doc->id);

        $this->assertSame(0, VehicleDocument::query()->count());
        Storage::disk(VehicleDocument::DISK)->assertMissing($doc->path);
        $this->assertStringContainsString('reg-2026.pdf', (string) ActivityLog::query()->where('action', 'car_paper_deleted')->sole()->description);
    }

    public function test_a_paper_of_another_car_cannot_be_deleted_from_this_cars_page(): void
    {
        $this->actingAs($this->owner());
        $other = Vehicle::query()->create(['name' => 'Kia', 'plate_no' => '9']);
        $doc = VehicleDocument::query()->create([
            'vehicle_id' => $other->id, 'year' => 2026, 'kind' => 'insurance',
            'original_name' => 'theirs.pdf', 'disk' => VehicleDocument::DISK, 'path' => 'rental_vehicle_docs/x.pdf',
        ]);

        Livewire::test(VehicleDocuments::class, ['vehicleId' => $this->car->id])->call('delete', $doc->id);

        $this->assertNotNull($doc->fresh());
    }

    public function test_what_is_not_a_document_is_refused(): void
    {
        $this->actingAs($this->owner());

        $this->post('/app/rental/vehicle/' . $this->car->id . '/documents', [
            'file' => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream'),
            'year' => 2026, 'kind' => 'registration',
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->upload(1800, 'registration', 'old.pdf')->assertStatus(422);
        $this->upload(2026, 'spaceship', 'x.pdf')->assertStatus(422);
        $this->assertSame(0, VehicleDocument::query()->count());
    }

    /**
     * The papers a car already had are copied in for the current year, pointing
     * at the files where they are. Deleting such a row leaves the file, because
     * the car's own field still uses it.
     */
    public function test_the_papers_a_car_already_had_are_listed_and_their_files_left_alone(): void
    {
        Storage::disk('public')->put('rental_vehicles/old-reg.pdf', 'pdf');
        DB::table('rental_vehicles')->where('id', $this->car->id)->update(['registration_doc' => 'rental_vehicles/old-reg.pdf']);

        $migration = require base_path('Modules/Rental/database/migrations/2026_11_16_900068_create_rental_vehicle_documents.php');
        $migration->up();
        $migration->up(); // running it twice copies nothing twice

        $doc = VehicleDocument::query()->sole();
        $this->assertSame([2026, 'registration', 'public'], [$doc->year, $doc->kind, $doc->disk]);

        $this->actingAs($this->owner());
        $this->get('/app/rental/vehicle-document/' . $doc->id)->assertOk();

        Livewire::test(VehicleDocuments::class, ['vehicleId' => $this->car->id])->call('delete', $doc->id);
        Storage::disk('public')->assertExists('rental_vehicles/old-reg.pdf');
    }
}
