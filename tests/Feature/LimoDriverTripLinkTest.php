<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Settings\Setting;
use App\Mail\CustomerDocumentMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Limousine\Livewire\TripRecord;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoTripRun;
use Modules\Limousine\Services\LimoQueueRows;
use Modules\Limousine\Support\DashboardReader;
use Modules\Limousine\Support\DriverTripLink;
use Modules\Rental\Models\Vehicle;
use Modules\WhatsApp\Models\AssistantConfiguration;
use Tests\TestCase;

/**
 * The driver's trip link: the copied message is only the trip number, thanks
 * and the link; the page shows the trip, takes a dashboard photo (KM + fuel,
 * read for him to check) and his location to start and again to end. The car's
 * KM follows, the customer is emailed when he starts, and he cannot start a
 * second trip while one is still running.
 */
final class LimoDriverTripLinkTest extends TestCase
{
    use DatabaseMigrations;

    private LimoDriver $driver;

    private LimoLeg $leg;

    private Vehicle $car;

    public static int $km = 52340;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        Mail::fake();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
        app(ModuleManager::class)->install('limousine');
        app(ModuleManager::class)->install('whatsapp');
        Route::middleware('web')->group(base_path('Modules/Limousine/routes/web.php'));
        Route::getRoutes()->refreshNameLookups();
        AssistantConfiguration::query()->create(['ai_api_key' => 'sk-test', 'enabled' => false]);
        Storage::fake(LimoTripRun::DISK);
        Setting::set('mail.customer_documents', true);
        self::$km = 52340;

        app()->instance(DashboardReader::class, new class implements DashboardReader {
            public function read(string $apiKey, string $model, string $image, string $mime): array
            {
                return ['readable' => true, 'km' => LimoDriverTripLinkTest::$km, 'fuel_eighths' => 6, 'fuel_readable' => true];
            }
        });

        $this->car = Vehicle::query()->create(['name' => 'GMC Yukon', 'plate_no' => '363899', 'active' => true, 'odometer' => 52000]);
        $this->driver = LimoDriver::query()->create(['name' => 'Sam Driver', 'phone' => '+973 3000 0000']);
        $this->leg = $this->trip();
    }

    private function trip(): LimoLeg
    {
        $customer = LimoCustomer::query()->create(['name' => 'Demo Co', 'email' => 'ops@demo.test']);
        $booking = LimoBooking::query()->create(['customer_id' => $customer->id, 'fare' => 30, 'pax_name' => 'Mr Demo', 'pax_contact' => '+973 3111 1111']);

        return $booking->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'status' => LimoLeg::STATUS_CONFIRMED,
            'from_location' => 'Demo Hotel', 'from_country' => 'BH', 'to_location' => 'Airport', 'start_at' => now()->addHours(3), 'days' => 1,
            'rate' => 30, 'rate_basis' => 'trip', 'net_amount' => 30, 'driver_id' => $this->driver->id, 'driver' => 'Sam Driver',
            'car_id' => $this->car->id,
        ]);
    }

    private function link(?LimoLeg $leg = null): string
    {
        return (string) DriverTripLink::for($leg ?? $this->leg);
    }

    private function photo(string $link): void
    {
        $this->post($link, ['action' => 'photo', 'file' => UploadedFile::fake()->image('dash.jpg', 800, 600)])
            ->assertOk()->assertJson(['readable' => true, 'km' => self::$km, 'fuel_eighths' => 6]);
    }

    public function test_the_copied_message_is_the_number_thanks_and_link(): void
    {
        $text = app(LimoQueueRows::class)->whatsappText($this->leg);

        $this->assertStringContainsString('Your booking No ' . $this->leg->reference, $text);
        $this->assertStringContainsString('Thank you Sam Driver for being part of Wanaan', $text);
        $this->assertStringContainsString('/driver-trip/' . $this->leg->id . '/' . $this->driver->id, $text);
        $this->assertStringNotContainsString('Demo Hotel', $text);
    }

    public function test_start_and_end_record_km_fuel_location_and_update_the_car(): void
    {
        $link = $this->link();
        $this->get($link)->assertOk()->assertSee('Mr Demo')->assertSee('+973 3111 1111')->assertSee('Bahrain')->assertSeeHtml('data-trip-go="start"');

        $this->photo($link);
        $this->post($link, ['action' => 'start', 'km' => 52340, 'fuel' => 6, 'lat' => 26.2, 'lng' => 50.5, 'accuracy' => 12])->assertOk();

        $run = LimoTripRun::query()->sole();
        $this->assertTrue($run->isRunning());
        $this->assertSame([52340, 6, 26.2], [$run->start_km, $run->start_fuel, $run->start_lat]);
        $this->assertSame(LimoLeg::STATUS_ACTIVE, $this->leg->fresh()?->status);
        $this->assertSame(52340, $this->car->fresh()?->odometer);
        Mail::assertSent(CustomerDocumentMail::class, fn (CustomerDocumentMail $m): bool => $m->hasTo('ops@demo.test') && str_contains($m->document->subject, 'on the way'));

        // End needs its own photo; a lower KM than the start is refused.
        $this->post($link, ['action' => 'end', 'km' => 52400, 'fuel' => 4])->assertStatus(422);
        self::$km = 52410;
        $this->photo($link);
        $this->post($link, ['action' => 'end', 'km' => 52300, 'fuel' => 4, 'lat' => 26.1, 'lng' => 50.6])->assertStatus(422);
        $this->post($link, ['action' => 'end', 'km' => 52410, 'fuel' => 4, 'lat' => 26.1, 'lng' => 50.6])->assertOk();

        $run->refresh();
        $this->assertSame(70, $run->distance());
        $this->assertSame(LimoLeg::STATUS_COMPLETED, $this->leg->fresh()?->status);
        $this->assertSame(52410, $this->car->fresh()?->odometer);
        $this->get($link)->assertOk()->assertSeeHtml('data-trip-ended');
    }

    public function test_one_trip_at_a_time(): void
    {
        $second = $this->trip();
        $this->photo($this->link());
        $this->post($this->link(), ['action' => 'start', 'km' => 52340, 'fuel' => 6])->assertOk();

        $this->get($this->link($second))->assertOk()->assertSeeHtml('data-trip-blocked');
        $this->post($this->link($second), ['action' => 'photo', 'file' => UploadedFile::fake()->image('d.jpg')])->assertStatus(422);
    }

    public function test_what_does_not_add_up_is_flagged_for_the_office(): void
    {
        $link = $this->link();
        self::$km = 51000;
        $this->photo($link);
        // Lower than the car's last reading, typed differently from the photo, no location.
        $this->post($link, ['action' => 'start', 'km' => 51500, 'fuel' => 6])->assertOk();

        $flags = implode(' | ', LimoTripRun::query()->sole()->flags ?? []);
        $this->assertStringContainsString("lower than the car's last reading 52000", $flags);
        $this->assertStringContainsString('changed by the driver', $flags);
        $this->assertStringContainsString('No location', $flags);
        // The car's KM never goes down.
        $this->assertSame(52000, $this->car->fresh()?->odometer);

        $this->grantEveryone('limousine.booking');
        Livewire::test(TripRecord::class, ['leg' => $this->leg->id])->assertSeeHtml('data-trip-flags')->assertSee('51,500');
    }

    public function test_the_link_stops_when_the_trip_goes_to_another_driver(): void
    {
        $link = $this->link();
        $this->leg->forceFill(['driver_id' => LimoDriver::query()->create(['name' => 'Other'])->id])->save();

        $this->get($link)->assertStatus(410)->assertSeeHtml('data-trip-invalid');
        $this->get(str_replace('/driver-trip/' . $this->leg->id, '/driver-trip/999', $link))->assertStatus(410);
    }
}
