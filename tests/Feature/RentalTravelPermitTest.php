<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Settings\Setting;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Rental\Livewire\TravelPermits;
use Modules\Rental\Models\Driver;
use Modules\Rental\Models\TravelPermit;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Services\TravelPermitIssuer;
use Modules\Rental\Services\TravelPermitPdf;
use Tests\TestCase;

/**
 * Travel authorizations: the letter that lets a named driver take a company
 * car to another Gulf country.
 *
 * It is built only from what is on file (never typed), it cannot outlive the
 * car's registration, and only the people the owner names may print it with
 * the company stamp.
 */
final class RentalTravelPermitTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 10:00:00');
        Storage::fake(TravelPermitPdf::DISK);
        app(ModuleManager::class)->install('rental');
        Route::middleware('web')->group(base_path('Modules/Rental/routes/web.php'));
        Route::getRoutes()->refreshNameLookups();
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

    /** @param array<string, mixed> $with */
    private function car(array $with = []): Vehicle
    {
        return Vehicle::query()->create($with + [
            'name' => 'Ford Territory', 'plate_no' => '111705', 'make' => 'Ford', 'model' => 'Territory',
            'year' => 2025, 'color' => 'White', 'registration_expiry' => '2027-07-30', 'active' => true,
        ]);
    }

    private function driver(string $name = 'Qassim Fuad Makhlooq', ?string $cpr = '870300628'): Driver
    {
        return Driver::query()->create(['name' => $name, 'cpr' => $cpr, 'active' => true]);
    }

    private function issue(Vehicle $car, Driver $driver, string $period = '7d', string $from = '2026-11-16'): TravelPermit
    {
        return app(TravelPermitIssuer::class)->issue($car, $driver, Carbon::parse('2026-11-16'), Carbon::parse($from), $period, 'Qassim');
    }

    public function test_a_letter_copies_the_car_and_driver_as_they_are_on_file(): void
    {
        $letter = $this->issue($this->car(), $this->driver(), '1y');

        $this->assertSame('TA/00001', $letter->refresh()->reference);
        $this->assertSame(['111705', 'Ford', 'Territory', 2025, 'White'], [$letter->plate_no, $letter->make, $letter->model, $letter->year, $letter->color]);
        $this->assertSame(['Qassim Fuad Makhlooq', '870300628'], [$letter->driver_name, $letter->driver_cpr]);
        $this->assertSame(['Wanaan Tours and Rental Car', '135164-1'], [$letter->owner_name, $letter->owner_no]);
        $this->assertSame('2026-11-16', $letter->issued_on->toDateString());
    }

    /** One day, 3, 7, 15 days, one month, six months, one year, from the start date. */
    public function test_each_period_runs_from_the_start_date(): void
    {
        $car = $this->car(['registration_expiry' => '2030-01-01']);
        $driver = $this->driver();

        foreach ([
            '1d' => '2026-11-17', '3d' => '2026-11-19', '7d' => '2026-11-23', '15d' => '2026-12-01',
            '1m' => '2026-12-16', '6m' => '2027-05-16', '1y' => '2027-11-16',
        ] as $period => $until) {
            $letter = $this->issue($car, $driver, $period);
            $this->assertSame($until, $letter->valid_to->toDateString(), $period);
            $this->assertFalse($letter->shortened);
        }
    }

    /** A letter cannot outlive the car's registration: the end is pulled back to its last day. */
    public function test_the_end_date_never_passes_the_cars_registration(): void
    {
        $car = $this->car(['registration_expiry' => '2026-12-10']);

        $letter = $this->issue($car, $this->driver(), '1y');

        $this->assertSame('2026-12-10', $letter->valid_to->toDateString());
        $this->assertTrue($letter->shortened);

        // A period that fits is left alone.
        $this->assertSame('2026-11-23', $this->issue($car, $this->driver('Ali Hasan Makhlooq', '900100200'), '7d')->valid_to->toDateString());
    }

    public function test_no_letter_without_the_cars_registration_details(): void
    {
        $driver = $this->driver();

        foreach ([
            'no expiry' => ['registration_expiry' => null],
            'expired' => ['registration_expiry' => '2026-11-01'],
            'no plate' => ['plate_no' => ''],
            'no make' => ['make' => null],
            'no model' => ['model' => null],
            'no colour' => ['color' => null],
            'no year' => ['year' => null],
        ] as $case => $with) {
            try {
                $this->issue($this->car($with), $driver);
                $this->fail("A letter was made for a car with {$case}.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('vehicle_id', $e->errors(), $case);
            }
        }

        $this->assertSame(0, TravelPermit::query()->count());
    }

    /** The officers expect first, father's and family name, and a personal number. */
    public function test_the_driver_needs_three_names_and_a_cpr(): void
    {
        $car = $this->car();

        foreach ([['Qassim Makhlooq', '870300628'], ['Qassim', '870300628'], ['Qassim Fuad Makhlooq', null], ['Qassim Fuad Makhlooq', '']] as [$name, $cpr]) {
            try {
                $this->issue($car, $this->driver($name, $cpr));
                $this->fail("A letter was made for [{$name}] with CPR [" . ($cpr ?? 'null') . '].');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('driver_id', $e->errors());
            }
        }

        // Extra spaces are not extra names, and four names are fine.
        $this->assertSame('Qassim Fuad Ali Makhlooq', $this->issue($car, $this->driver('  Qassim   Fuad Ali  Makhlooq ', '870300628'))->driver_name);
    }

    /** Reprinting next month gives the same letter even if the records changed since. */
    public function test_the_letter_keeps_what_was_true_when_it_was_made(): void
    {
        $car = $this->car();
        $driver = $this->driver();
        $letter = $this->issue($car, $driver);

        $car->update(['color' => 'Black']);
        $driver->update(['name' => 'Someone Else Entirely']);

        $this->assertSame('White', $letter->refresh()->color);
        $this->assertSame('Qassim Fuad Makhlooq', $letter->driver_name);
    }

    public function test_the_page_makes_a_letter_and_says_who_already_holds_one(): void
    {
        $this->actingAs($this->owner());
        $car = $this->car(['registration_expiry' => '2026-12-10']);
        $driver = $this->driver();

        Livewire::test(TravelPermits::class)
            ->set('vehicle_id', (string) $car->id)
            ->set('driver_id', (string) $driver->id)
            ->call('setPeriod', '1y')
            ->assertSee('Valid until 10/12/2026')
            ->assertSee('ends on its last day')
            ->call('issue')
            ->assertHasNoErrors()
            ->assertSee('TA/00001')
            ->assertSee('In force')
            // Picking the same pair again shows what he already holds.
            ->set('vehicle_id', (string) $car->id)
            ->set('driver_id', (string) $driver->id)
            ->assertSee('already holds an authorization for this car until 10/12/2026');

        $this->assertSame('Qassim', TravelPermit::query()->sole()->created_by);
    }

    public function test_the_page_names_what_is_missing_and_will_not_save(): void
    {
        $this->actingAs($this->owner());
        $car = $this->car(['registration_expiry' => null, 'color' => null]);
        $driver = $this->driver('Qassim Makhlooq');

        Livewire::test(TravelPermits::class)
            ->set('vehicle_id', (string) $car->id)
            ->set('driver_id', (string) $driver->id)
            ->assertSee('The registration expiry date is not filled in on the car.')
            ->assertSee('Colour is not filled in on the car.')
            ->assertSee('must have three names')
            ->call('issue')
            ->assertHasErrors(['vehicle_id', 'driver_id']);

        $this->assertSame(0, TravelPermit::query()->count());
    }

    public function test_the_list_can_show_only_letters_still_valid_and_be_searched(): void
    {
        $this->actingAs($this->owner());
        $car = $this->car(['registration_expiry' => '2030-01-01']);
        $this->issue($car, $this->driver('Old Letter Holder', '111111111'), '1d', '2026-10-01');
        $this->issue($car, $this->driver('Current Letter Holder', '222222222'), '1m');

        // By letter number: the names also sit in the driver picker above.
        Livewire::test(TravelPermits::class)
            ->assertSee('TA/00001')
            ->assertSee('Ended')
            ->set('current', true)
            ->assertDontSee('TA/00001')
            ->assertSee('TA/00002')
            ->set('current', false)
            ->set('search', '111111111')
            ->assertSee('TA/00001')
            ->assertDontSee('TA/00002');
    }

    /** PDF only, and the stamp only for those the owner allowed. */
    public function test_the_stamped_pdf_is_for_the_owner_and_those_he_names(): void
    {
        $letter = $this->issue($this->car(), $this->driver());
        Storage::disk(TravelPermitPdf::DISK)->put('travel_permit_artwork/stamp.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', true));
        Setting::set('rental.permit.stamp', 'travel_permit_artwork/stamp.png');

        $plain = User::factory()->create(['is_admin' => true]);
        $named = User::factory()->create(['is_admin' => true, 'can_stamp_travel_permits' => true]);

        $this->actingAs($plain);
        $response = $this->get('/app/rental/travel-permit/' . $letter->id . '/pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->get('/app/rental/travel-permit/' . $letter->id . '/pdf?stamp=1')->assertForbidden();
        $this->get('/app/rental/travel-permit/artwork/stamp')->assertForbidden();
        Livewire::test(TravelPermits::class)->assertDontSee('With stamp');

        $this->actingAs($named);
        $this->get('/app/rental/travel-permit/' . $letter->id . '/pdf?stamp=1')->assertOk();
        Livewire::test(TravelPermits::class)->assertSee('With stamp');

        $this->actingAs($this->owner());
        $this->get('/app/rental/travel-permit/' . $letter->id . '/pdf?stamp=1')->assertOk();

        // Each stamped print is on the record.
        $this->assertSame(2, ActivityLog::query()->where('action', 'travel_permit_stamped')->count());
    }

    public function test_a_stamped_letter_is_refused_while_no_stamp_is_uploaded(): void
    {
        $letter = $this->issue($this->car(), $this->driver());
        $this->actingAs($this->owner());

        $this->get('/app/rental/travel-permit/' . $letter->id . '/pdf?stamp=1')->assertStatus(409);
    }

    public function test_only_the_owner_uploads_the_stamp_and_chooses_who_may_use_it(): void
    {
        $staff = User::factory()->create(['name' => 'Hasan Makhlooq', 'is_admin' => true]);

        $this->actingAs($staff);
        $this->post('/app/rental/travel-permit/artwork', ['kind' => 'stamp', 'file' => UploadedFile::fake()->image('stamp.png')], ['Accept' => 'application/json'])->assertForbidden();
        Livewire::test(TravelPermits::class)
            ->assertDontSee('Who may print with the stamp')
            ->call('setStamper', $staff->id, true)
            ->assertForbidden();
        $this->assertFalse($staff->refresh()->canStampTravelPermits());

        $this->actingAs($this->owner());
        $this->post('/app/rental/travel-permit/artwork', ['kind' => 'stamp', 'file' => UploadedFile::fake()->image('stamp.png')], ['Accept' => 'application/json'])->assertOk();
        $this->assertNotNull(TravelPermitPdf::artworkPath('stamp'));
        // Not a picture: refused.
        $this->post('/app/rental/travel-permit/artwork', ['kind' => 'signature', 'file' => UploadedFile::fake()->create('sig.svg', 4, 'image/svg+xml')], ['Accept' => 'application/json'])->assertStatus(422);

        Livewire::test(TravelPermits::class)
            ->assertSee('Who may print with the stamp')
            ->call('setStamper', $staff->id, true);
        $this->assertTrue($staff->refresh()->canStampTravelPermits());
    }

    public function test_the_migration_names_via_ali_abbas_and_hasan(): void
    {
        $yes = [
            User::factory()->create(['name' => 'via', 'email' => 'v@wanaan-bh.com']),
            User::factory()->create(['name' => 'Ali Makhlooq', 'email' => 'a1@wanaan-bh.com']),
            User::factory()->create(['name' => 'Someone', 'email' => 'hamdanabbas98@gmail.com']),
            User::factory()->create(['name' => 'Hasan Makhlooq', 'email' => 'h@wanaan-bh.com']),
        ];
        $no = [
            User::factory()->create(['name' => 'Alison Brown', 'email' => 'alison@wanaan-bh.com']),
            User::factory()->create(['name' => 'Hassan Other', 'email' => 'hs@wanaan-bh.com']),
        ];

        $migration = require base_path('database/migrations/2026_11_16_100002_add_can_stamp_travel_permits_to_users.php');
        $migration->up();

        foreach ($yes as $user) {
            $this->assertTrue($user->refresh()->canStampTravelPermits(), $user->name);
        }
        foreach ($no as $user) {
            $this->assertFalse($user->refresh()->canStampTravelPermits(), $user->name);
        }
    }

    /** The link sits on the main dashboard, for those who may open the page. */
    public function test_the_dashboard_links_to_it_for_those_who_may_open_it(): void
    {
        $this->actingAs($this->owner());
        $this->get('/')->assertOk()->assertSee('data-dashboard-travel-permits', false);

        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get('/')->assertOk()->assertDontSee('data-dashboard-travel-permits', false);
    }

    public function test_staff_with_no_access_to_orders_cannot_open_the_page(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(TravelPermits::class)->assertForbidden();
    }
}
