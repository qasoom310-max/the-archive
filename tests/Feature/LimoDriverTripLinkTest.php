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

    public function test_the_copied_message_has_two_short_links_and_no_address(): void
    {
        $text = app(LimoQueueRows::class)->whatsappText($this->leg);

        $this->assertStringContainsString('*Ref. # ' . $this->leg->reference . '*', $text);
        $this->assertStringContainsString((string) $this->leg->start_at?->isoFormat('HH:mm'), $text);
        // English only in the message; the Arabic is on the pages.
        $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $text);
        $this->assertStringContainsString('Thank you Sam Driver for being part of Wanaan', $text);
        // Every line apart, and never the phone the trip label carries.
        $this->assertStringContainsString("*\n\n", $text);
        $this->leg->forceFill(['driver' => 'Sam Driver · +97330000000'])->save();
        $this->assertStringNotContainsString('+97330000000', app(LimoQueueRows::class)->whatsappText($this->leg));
        $this->assertMatchesRegularExpression('#/d/[0-9a-z]+\.[0-9a-z]+\.[0-9a-z]+\.[A-Za-z0-9xy]{12}#', $text);
        $this->assertMatchesRegularExpression('#/b/[0-9a-z.]+\.[A-Za-z0-9xy]{12}#', $text);
        $this->assertStringContainsString(\Modules\Limousine\Support\DriverBillLink::NOTE_EN, $text);
        $this->assertStringNotContainsString('Demo Hotel', $text);
        $this->assertStringNotContainsString('signature=', $text);

        // The short trip link opens the trip; a changed code does not.
        $short = (string) DriverTripLink::short($this->leg);
        $this->assertLessThan(70, strlen($short));
        $target = $this->get($short)->assertRedirect()->headers->get('Location');
        $this->get((string) $target)->assertOk()->assertSeeHtml('data-trip-step="start"');
        $this->get(substr($short, 0, -1) . (str_ends_with($short, 'a') ? 'b' : 'a'))->assertNotFound();

        $bills = (string) \Modules\Limousine\Support\DriverBillLink::short($this->leg);
        $billsTarget = $this->get($bills)->assertRedirect()->headers->get('Location');
        $this->get((string) $billsTarget)->assertOk()->assertSee('Send your bills');
    }

    public function test_the_address_and_phone_stay_hidden_until_the_driver_starts(): void
    {
        $link = $this->link();
        $this->get($link)->assertOk()->assertSee('Mr Demo')->assertSee('Bahrain')
            ->assertDontSee('Demo Hotel')->assertDontSee('+973 3111 1111')->assertSeeHtml('data-address-locked');

        $this->photo($link);
        $this->post($link, ['action' => 'start', 'km' => 52340, 'fuel' => 6, 'lat' => 26.2, 'lng' => 50.5])->assertOk();

        $this->get($link)->assertOk()->assertSee('Demo Hotel')->assertSee('+973 3111 1111')->assertDontSeeHtml('data-address-locked');
    }

    public function test_the_office_ends_a_forgotten_trip_so_the_driver_can_start_the_next(): void
    {
        $second = $this->trip();
        $this->photo($this->link());
        $this->post($this->link(), ['action' => 'start', 'km' => 52340, 'fuel' => 6])->assertOk();
        $this->get($this->link($second))->assertSeeHtml('data-trip-blocked');

        $this->grantEveryone('limousine.booking');
        $run = LimoTripRun::query()->sole();
        Livewire::test(TripRecord::class, ['leg' => $this->leg->id])
            ->assertSeeHtml('data-office-end')
            ->call('endForDriver', $run->id)->assertHasErrors('endNote')
            ->set('endNote', 'Driver forgot, confirmed by phone')->set('endKm', '52300')
            ->call('endForDriver', $run->id)->assertHasErrors('endKm')
            ->set('endKm', '52390')->call('endForDriver', $run->id)->assertHasNoErrors()
            ->assertSeeHtml('data-ended-by-office');

        $run->refresh();
        $this->assertTrue($run->isEnded());
        $this->assertNotNull($run->ended_by);
        $this->assertSame(52390, $this->car->fresh()?->odometer);
        $this->assertSame(LimoLeg::STATUS_COMPLETED, $this->leg->fresh()?->status);
        $this->get($this->link($second))->assertDontSeeHtml('data-trip-blocked');
    }

    public function test_abdullah_tells_operations_once_when_a_trip_is_not_started(): void
    {
        Mail::fake();
        \App\Erp\Settings\Setting::set('abdullah.ops_email', 'ops@wanaan.test');
        $this->leg->forceFill(['start_at' => \Illuminate\Support\Carbon::now()->subMinutes(20)])->save();
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-11-26 10:00:00'));
        $this->leg->forceFill(['start_at' => \Illuminate\Support\Carbon::now()->subMinutes(20)])->save();

        $starts = app(\Modules\Limousine\Services\AbdullahTripStarts::class);
        $this->assertSame(1, $starts->run());
        $this->assertSame(0, $starts->run());
        Mail::assertSent(\App\Erp\Abdullah\AbdullahMail::class, static fn ($m): bool => $m->hasTo('ops@wanaan.test'));

        // A trip the driver started is never reported.
        $other = $this->trip();
        $other->forceFill(['start_at' => \Illuminate\Support\Carbon::now()->subMinutes(30)])->save();
        LimoTripRun::query()->create(['leg_id' => $other->id, 'driver_id' => $this->driver->id, 'started_at' => \Illuminate\Support\Carbon::now()->subMinutes(35)]);
        $this->assertSame(0, $starts->run());
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_the_monthly_driver_record_counts_each_kind(): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-12-20 12:00:00'));
        $done = $this->trip();
        $missed = $this->trip();
        $done->forceFill(['start_at' => '2026-12-05 09:00:00'])->save();
        $missed->forceFill(['start_at' => '2026-12-06 09:00:00'])->save();
        $this->leg->forceFill(['start_at' => '2027-01-02 09:00:00'])->save();
        LimoTripRun::query()->create(['leg_id' => $done->id, 'driver_id' => $this->driver->id, 'started_at' => '2026-12-05 08:50:00', 'ended_at' => '2026-12-05 10:00:00', 'start_km' => 100, 'end_km' => 160]);

        $this->grantEveryone('limousine.booking');
        Livewire::test(\Modules\Limousine\Livewire\DriverTripReport::class)
            ->set('month', '2026-12')
            ->assertViewHas('rows', fn (array $rows): bool => count($rows) === 1
                && $rows[0]['trips'] === 2 && $rows[0]['done'] === 1 && $rows[0]['missed'] === 1 && $rows[0]['km'] === 60)
            ->assertSee('50%');
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_start_and_end_record_km_fuel_location_and_update_the_car(): void
    {
        $link = $this->link();
        $this->get($link)->assertOk()->assertSee('Mr Demo')->assertSee('Bahrain')->assertSeeHtml('data-trip-go="start"');

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
