<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Settings\Setting;
use App\Mail\CustomerDocumentMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Mail;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\TripCrew;
use Modules\Rental\Models\Vehicle;
use Tests\TestCase;

/**
 * Putting a car and a driver on a trip emails the customer who is coming,
 * their number, and the car and plate to look for — once per pair, only for a
 * trip still to happen, and only where customer emails are switched on.
 */
final class LimoDriverAssignedEmailTest extends TestCase
{
    use DatabaseMigrations;

    private LimoLeg $leg;

    private Vehicle $car;

    private LimoDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        Mail::fake();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('rental');
        app(ModuleManager::class)->install('limousine');

        Setting::set('company.name', 'Wanaan Car Rental W.L.L');
        Setting::set('mail.customer_documents', true);
        Setting::set('company.customer_terms', '# Terms');

        $customer = LimoCustomer::query()->create([
            'name' => 'Gulf Co', 'type' => LimoCustomer::TYPE_COMPANY, 'phone' => '17000000',
            'email' => 'accounts@gulf.test', 'service_email' => 'ops@gulf.test',
        ]);
        $booking = LimoBooking::query()->create([
            'customer_id' => $customer->id, 'status' => LimoBooking::STATUS_QUEUE, 'pax_name' => 'Helen Friberg',
        ]);
        $this->leg = LimoLeg::query()->create([
            'legable_type' => LimoBooking::class, 'legable_id' => $booking->id,
            'status' => LimoLeg::STATUS_QUEUE, 'start_at' => now()->addDay()->setTime(8, 30),
            'from_location' => 'Four Seasons', 'to_location' => 'Bahrain airport',
        ]);
        $this->car = Vehicle::query()->create(['name' => 'GMC Yukon', 'plate_no' => '363899', 'color' => 'Black', 'active' => true]);
        $this->driver = LimoDriver::query()->create(['name' => 'Habib Ali', 'phone' => '+973 3999 1234', 'active' => true]);
    }

    private function assign(?int $car, ?int $driver): void
    {
        $result = app(TripCrew::class)->assign($this->leg->fresh() ?? $this->leg, $car, $driver);
        $this->assertSame([], $result['errors']);
    }

    public function test_assigning_a_car_and_driver_emails_the_customer_the_details(): void
    {
        $this->assign($this->car->id, $this->driver->id);

        Mail::assertSent(CustomerDocumentMail::class, 1);
        Mail::assertSent(CustomerDocumentMail::class, function (CustomerDocumentMail $mail): bool {
            $facts = array_column($mail->document->facts, 1, 0);

            return $mail->hasTo('ops@gulf.test')
                && str_contains($mail->document->subject, 'Your driver for trip ' . $this->leg->reference)
                && $facts['Driver'] === 'Habib Ali'
                && $facts["Driver's phone"] === '+973 3999 1234'
                && $facts['Car'] === 'GMC Yukon (Black)'
                && $facts['Plate no.'] === '363899'
                && $facts['Passenger'] === 'Helen Friberg'
                && $facts['Pick up'] === 'Four Seasons'
                // A note about a trip, not a sale: no PDFs, no terms.
                && $mail->attachmentFiles === []
                // And it renders: logo-less header, the summary, no attachment list.
                && str_contains($mail->render(), '363899');
        });
    }

    public function test_the_same_pair_saved_again_sends_nothing_but_a_new_driver_does(): void
    {
        $this->assign($this->car->id, $this->driver->id);
        $this->assign($this->car->id, $this->driver->id);
        Mail::assertSent(CustomerDocumentMail::class, 1);

        $other = LimoDriver::query()->create(['name' => 'Sohail Khan', 'phone' => '33000000', 'active' => true]);
        $this->assign($this->car->id, $other->id);
        Mail::assertSent(CustomerDocumentMail::class, 2);
    }

    public function test_a_car_alone_sends_nothing(): void
    {
        $this->assign($this->car->id, null);

        Mail::assertNothingSent();
    }

    public function test_a_trip_already_past_its_pick_up_is_not_emailed(): void
    {
        $this->leg->forceFill(['start_at' => now()->subHour()])->saveQuietly();

        $this->assign($this->car->id, $this->driver->id);

        Mail::assertNothingSent();
    }

    public function test_nothing_is_sent_when_customer_emails_are_off(): void
    {
        Setting::set('mail.customer_documents', false);

        $this->assign($this->car->id, $this->driver->id);

        Mail::assertNothingSent();
    }

    public function test_a_customer_with_no_email_is_skipped_quietly(): void
    {
        LimoCustomer::query()->update(['email' => null, 'service_email' => null]);

        $this->assign($this->car->id, $this->driver->id);

        Mail::assertNothingSent();
        $this->assertSame(LimoLeg::STATUS_ACTIVE, $this->leg->fresh()?->status);
    }
}
