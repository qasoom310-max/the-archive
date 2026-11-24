<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Abdullah\Abdullah;
use App\Erp\Abdullah\AbdullahMail;
use App\Erp\Abdullah\Voice;
use App\Erp\Modules\ModuleManager;
use App\Erp\Settings\Setting;
use App\Erp\Settings\SettingManager;
use App\Livewire\Abdullah\Box;
use App\Livewire\Settings\AbdullahSettings;
use App\Models\AbdullahAlert;
use App\Models\AbdullahMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoLocation;
use Modules\Limousine\Services\AbdullahBookingWatch;
use Modules\Limousine\Services\AbdullahTrips;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Services\AbdullahOrderWatch;
use Modules\WhatsApp\Models\AssistantConfiguration;
use Tests\TestCase;

/**
 * Abdullah, the staff assistant: he chases trips with no driver on a capped
 * timer, praises and pushes on new bookings, tells a person about a slip the
 * ERP can prove (once), and talks back in the box, in each person's language.
 */
final class AbdullahTest extends TestCase
{
    use DatabaseMigrations;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingManager::class)->flush();
        app(ModuleManager::class)->install('limousine');
        app(ModuleManager::class)->install('whatsapp');
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-21 08:00:00', config('app.timezone')));

        $this->staff = User::factory()->create(['name' => 'Ali Ops', 'is_admin' => true, 'trip_reminders' => true]);
        $this->actingAs($this->staff);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeVoice(): void
    {
        AssistantConfiguration::query()->create(['ai_api_key' => 'sk-test', 'enabled' => false]);
        app()->instance(Voice::class, new class implements Voice {
            public function write(string $apiKey, string $model, string $language, string $kind, array $facts, string $userName, array $history = [], ?string $userText = null): string
            {
                return 'AI[' . $language . '][' . $kind . '] ' . json_encode($facts) . ($userText !== null ? ' reply-to:' . $userText : '');
            }
        });
    }

    private function trip(string $time = '14:00', ?int $driverId = null, float $rate = 20, string $from = 'Airport', string $to = 'Hotel'): LimoLeg
    {
        $booking = LimoBooking::query()->create([
            'customer_id' => LimoCustomer::query()->create(['name' => 'Demo Co ' . uniqid(), 'type' => 'company'])->id,
            'fare' => $rate,
            'prepared_by' => $this->staff->name,
        ]);

        return $booking->legs()->create([
            'sequence' => 0, 'service_type' => 'transfer', 'status' => LimoLeg::STATUS_CONFIRMED,
            'from_location' => $from, 'to_location' => $to,
            'start_at' => '2026-11-21 ' . $time . ':00', 'days' => 1,
            'rate' => $rate, 'rate_basis' => 'trip', 'net_amount' => $rate,
            'driver_id' => $driverId,
        ]);
    }

    public function test_a_trip_today_without_a_driver_is_emailed_on_the_timer_and_stops_when_fixed(): void
    {
        $leg = $this->trip();
        $trips = app(AbdullahTrips::class);

        $this->assertSame(1, $trips->run()['emails']);
        Mail::assertSent(AbdullahMail::class, static fn (AbdullahMail $m): bool => $m->hasTo(Abdullah::DEFAULT_OPS_EMAIL)
            && $m->rows[0]['reference'] === (string) $leg->reference);

        // Not again before 15 minutes are up.
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->assertSame(0, $trips->run()['emails']);

        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $this->assertSame(1, $trips->run()['emails']);
        $this->assertSame(2, AbdullahAlert::query()->where('key', AbdullahTrips::key($leg))->value('sent_count'));

        // A driver is put on: he stops by himself.
        $leg->forceFill(['driver_id' => 1, 'car_id' => 1])->save();
        Carbon::setTestNow(Carbon::now()->addMinutes(15));
        $this->assertSame(0, $trips->run()['emails']);
        $this->assertNotNull(AbdullahAlert::query()->where('key', AbdullahTrips::key($leg))->value('resolved_at'));
    }

    public function test_after_the_last_reminder_the_owner_is_told_once(): void
    {
        Setting::set('abdullah.trip_max', 2);
        $owner = User::factory()->create(['is_super_admin' => true, 'is_admin' => true, 'email' => 'owner@example.com']);
        $this->trip();
        $trips = app(AbdullahTrips::class);

        $trips->run();
        Carbon::setTestNow(Carbon::now()->addMinutes(15));
        $trips->run();
        Carbon::setTestNow(Carbon::now()->addMinutes(15));
        $result = $trips->run();

        $this->assertSame(['emails' => 0, 'escalations' => 1], $result);
        Mail::assertSent(AbdullahMail::class, 3);
        Mail::assertSent(AbdullahMail::class, static fn (AbdullahMail $m): bool => $m->hasTo('owner@example.com'));

        Carbon::setTestNow(Carbon::now()->addMinutes(15));
        $this->assertSame(['emails' => 0, 'escalations' => 0], $trips->run());
        $this->assertSame(1, AbdullahMessage::query()->where('user_id', $owner->id)->where('kind', 'escalation')->where('channel', 'box')->count());
    }

    public function test_im_handling_it_in_the_box_stops_the_emails(): void
    {
        $this->trip();
        $trips = app(AbdullahTrips::class);
        $trips->run();

        $message = AbdullahMessage::query()->where('user_id', $this->staff->id)->where('kind', 'trip')->firstOrFail();

        Livewire::test(Box::class)
            ->call('tick')
            ->assertSet('open', true)
            ->assertSeeHtml('data-abdullah-handling')
            ->call('handling', $message->id)
            ->assertDontSeeHtml('data-abdullah-handling');

        Carbon::setTestNow(Carbon::now()->addMinutes(15));
        $this->assertSame(0, $trips->run()['emails']);
        $this->assertSame($this->staff->id, AbdullahAlert::query()->where('kind', 'trip')->value('acknowledged_by'));
    }

    public function test_trips_coming_due_at_different_times_share_one_email(): void
    {
        $trips = app(AbdullahTrips::class);
        $this->trip();
        $this->assertSame(1, $trips->run()['emails']);

        // A second trip booked 5 minutes later waits for the shared clock
        // instead of getting an email of its own.
        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $second = $this->trip();
        $this->assertSame(0, $trips->run()['emails']);

        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        $this->assertSame(1, $trips->run()['emails']);
        Mail::assertSent(AbdullahMail::class, 2);
        Mail::assertSent(AbdullahMail::class, static fn (AbdullahMail $m): bool => count($m->rows) === 2
            && in_array((string) $second->reference, array_column($m->rows, 'reference'), true));
    }

    public function test_trips_of_other_days_and_trips_with_a_crew_are_left_alone(): void
    {
        $this->trip('14:00', 1)->forceFill(['car_id' => 1])->save();
        $tomorrow = $this->trip();
        $tomorrow->forceFill(['start_at' => '2026-11-22 09:00:00'])->save();

        $this->assertSame(0, app(AbdullahTrips::class)->run()['emails']);
        Mail::assertNothingSent();
    }

    public function test_a_new_booking_gets_praise_with_the_persons_numbers_and_a_question_on_an_odd_price(): void
    {
        $this->fakeVoice();
        for ($i = 0; $i < 8; $i++) {
            $this->trip('15:00', 1, 20);
        }
        $leg = $this->trip('16:00', null, 200);

        app(AbdullahBookingWatch::class)->saved($leg->legable, true, $this->staff);

        $message = AbdullahMessage::query()->where('kind', 'booking')->firstOrFail();
        $this->assertSame(9, $message->facts['today_count']);
        $this->assertSame('rate', $message->facts['price_check']['problem']);

        Livewire::test(Box::class)->call('tick')->assertSet('open', true)->assertSee('AI[ar][booking]');
        $this->assertTrue((bool) $message->fresh()?->by_ai);

        // Asked inside the praise: not said a second time as its own message.
        app(AbdullahBookingWatch::class)->saved($leg->legable, false, $this->staff);
        $this->assertSame(0, AbdullahMessage::query()->where('kind', 'check')->count());
    }

    public function test_a_place_one_letter_off_a_saved_one_is_told_once_and_closed_when_fixed(): void
    {
        LimoLocation::query()->create(['name' => 'Bahrain Airport']);
        $leg = $this->trip('14:00', 1, 20, 'Bahrian Airport');
        $watch = app(AbdullahBookingWatch::class);

        $watch->saved($leg->legable, false, $this->staff);
        $watch->saved($leg->legable, false, $this->staff);

        $checks = AbdullahMessage::query()->where('kind', 'check')->get();
        $this->assertCount(1, $checks);
        $this->assertSame('Bahrain Airport', $checks[0]->facts['saved']);

        $leg->forceFill(['from_location' => 'Bahrain Airport'])->save();
        $watch->saved($leg->legable, false, $this->staff);
        $this->assertNotNull(AbdullahAlert::query()->where('key', 'check:place:leg:' . $leg->id . ':from')->value('resolved_at'));
    }

    public function test_more_paid_than_the_total_is_told(): void
    {
        $leg = $this->trip('14:00', 1, 20);
        $leg->legable->forceFill(['advance' => 200])->save();

        app(AbdullahBookingWatch::class)->saved($leg->legable, false, $this->staff);

        $this->assertSame('overpaid', AbdullahMessage::query()->where('kind', 'check')->value('facts')['problem'] ?? null);
    }

    public function test_a_new_customer_like_a_saved_one_is_told(): void
    {
        LimoCustomer::query()->create(['name' => 'Gulf Travel', 'phone' => '+973 3300 1122']);
        $new = LimoCustomer::query()->create(['name' => 'Gulf Travl', 'phone' => '39998877']);

        app(AbdullahBookingWatch::class)->customerAdded($new, $this->staff);

        $this->assertSame('Gulf Travel', AbdullahMessage::query()->where('kind', 'check')->firstOrFail()->facts['existing']);
    }

    public function test_without_claude_he_uses_a_plain_sentence_in_the_persons_language(): void
    {
        $this->trip('14:00', 1, 20);
        $leg = LimoLeg::query()->firstOrFail();
        app(AbdullahBookingWatch::class)->saved($leg->legable, true, $this->staff);

        Livewire::test(Box::class)->call('tick');

        $message = AbdullahMessage::query()->where('kind', 'booking')->firstOrFail();
        $this->assertFalse($message->by_ai);
        $this->assertStringContainsString((string) $leg->legable->reference, (string) $message->body);
    }

    public function test_the_monthly_limit_turns_claude_off_until_next_month(): void
    {
        $this->fakeVoice();
        Setting::set('abdullah.monthly_limit', 0);

        $answer = app(Abdullah::class)->reply($this->staff, 'hello');

        $this->assertFalse($answer->by_ai);
    }

    public function test_the_box_answers_in_the_persons_language(): void
    {
        $this->fakeVoice();
        $this->staff->forceFill(['abdullah_language' => 'tl'])->save();

        Livewire::test(Box::class)
            ->call('openBox')
            ->set('text', 'What do I do next?')
            ->call('send')
            ->assertSee('AI[tl][chat]')
            ->assertSee('What do I do next?')
            ->call('setLanguage', 'ml');

        $this->assertSame('ml', $this->staff->fresh()?->getAttribute('abdullah_language'));
        $this->assertSame(2, AbdullahMessage::query()->where('user_id', $this->staff->id)->count());
    }

    public function test_a_person_switched_off_never_sees_him(): void
    {
        $this->staff->forceFill(['abdullah_off' => true])->save();

        Livewire::test(Box::class)->assertDontSeeHtml('data-abdullah-button');
        $this->assertNull(app(Abdullah::class)->tell($this->staff, 'chat', []));
    }

    public function test_a_new_rental_order_at_twice_the_cars_rate_is_questioned(): void
    {
        app(ModuleManager::class)->install('rental');
        $car = Vehicle::query()->create(['name' => 'Yaris', 'daily_rate' => 10]);
        $order = RentalOrder::query()->create([
            'customer_id' => RentalCustomer::query()->create(['name' => 'Ahmed'])->id,
            'vehicle_id' => $car->id, 'rate_type' => 'daily', 'rate' => 25, 'days' => 2,
            'start_date' => now(), 'end_date' => now()->addDays(2),
            'created_by_user_id' => $this->staff->id,
        ]);

        app(AbdullahOrderWatch::class)->saved($order, true, $this->staff);

        $facts = AbdullahMessage::query()->where('kind', 'booking')->firstOrFail()->facts;
        $this->assertSame('rental_rate', $facts['price_check']['problem'] ?? null);
        $this->assertSame(1, $facts['today_count']);
    }

    public function test_only_the_owner_opens_the_abdullah_settings(): void
    {
        Livewire::test(AbdullahSettings::class)->assertForbidden();

        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'is_admin' => true]));
        Livewire::test(AbdullahSettings::class)
            ->set('tripEvery', '20')
            ->set('tripMax', '6')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(20, app(Abdullah::class)->tripEveryMinutes());
        $this->assertSame(6, app(Abdullah::class)->tripMax());
    }

    public function test_anyone_not_named_by_the_owner_gets_arabic(): void
    {
        $this->assertNull($this->staff->fresh()?->getAttribute('abdullah_language'));
        $this->assertSame('ar', app(Abdullah::class)->languageFor($this->staff));
    }
}
