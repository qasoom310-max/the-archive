<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Settings\Setting;
use App\Mail\CustomerDocumentMail;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Services\BalanceReminders;
use Modules\Limousine\Services\WebBookingToLimousine;
use Modules\Rental\Models\RentalWebBooking;
use Tests\TestCase;

/**
 * A limousine trip paid in part on the website: the customer is emailed the
 * receipt (with the invoice and what is still owed), and reminded of the rest
 * about an hour before the trip.
 */
final class WebBookingBalanceEmailTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        Mail::fake();
        app(ModuleManager::class)->install('rental');
        app(ModuleManager::class)->install('limousine');
        Setting::set('mail.customer_documents', true);
        Carbon::setTestNow('2026-11-17 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function webTrip(array $overrides = []): RentalWebBooking
    {
        return RentalWebBooking::query()->create(array_merge([
            'source_reference' => '16001-1',
            'service' => 'limousine',
            'first_name' => 'Helen',
            'last_name' => 'Friberg',
            'phone' => '+97339000000',
            'email' => 'helen@example.com',
            'product' => 'Airport Transfer — Sedan',
            'pickup_location' => 'Bahrain Airport',
            'dropoff_location' => 'Juffair',
            'pickup_at' => '2026-11-18 10:00:00',
            'total' => 50.0,
            'amount_paid' => 10.0,
            'payment_mode' => 'Tap',
            'payment_status' => 'paid',
            'status' => RentalWebBooking::STATUS_PENDING,
        ], $overrides));
    }

    private function convert(RentalWebBooking $web): LimoBooking
    {
        return app(WebBookingToLimousine::class)->convert($web);
    }

    public function test_a_part_paid_website_trip_emails_the_receipt_and_the_balance(): void
    {
        $this->convert($this->webTrip());

        Mail::assertSent(CustomerDocumentMail::class, 1);
        Mail::assertSent(CustomerDocumentMail::class, static fn (CustomerDocumentMail $mail): bool => $mail->hasTo('helen@example.com')
            && in_array(['Balance due', '40.00 BD'], array_map(static fn (array $f): array => [$f[0], preg_replace('/[^0-9.]/', '', $f[1]) . ' BD'], $mail->document->facts), true));
    }

    public function test_it_goes_to_the_address_typed_on_the_website_and_fills_a_blank_one(): void
    {
        $known = LimoCustomer::query()->create(['name' => 'Helen F', 'phone' => '39000000']);

        $booking = $this->convert($this->webTrip());

        $this->assertSame((int) $known->id, (int) $booking->customer_id);
        $this->assertSame('helen@example.com', $known->refresh()->email);
        Mail::assertSent(CustomerDocumentMail::class, static fn (CustomerDocumentMail $mail): bool => $mail->hasTo('helen@example.com'));
    }

    public function test_an_unpaid_website_trip_sends_nothing(): void
    {
        $this->convert($this->webTrip(['amount_paid' => null, 'payment_mode' => 'Pay Later', 'payment_status' => 'pending']));

        Mail::assertNothingSent();
    }

    public function test_the_reminder_goes_an_hour_before_and_only_once(): void
    {
        $booking = $this->convert($this->webTrip());
        Mail::fake();

        // Two hours before: too early.
        $this->assertSame(0, app(BalanceReminders::class)->run(Carbon::parse('2026-11-18 08:00:00')));

        // Within the hour.
        $this->assertSame(1, app(BalanceReminders::class)->run(Carbon::parse('2026-11-18 09:05:00')));
        Mail::assertSent(CustomerDocumentMail::class, 1);
        Mail::assertSent(CustomerDocumentMail::class, static fn (CustomerDocumentMail $mail): bool => $mail->hasTo('helen@example.com')
            && str_contains($mail->document->subject, 'Balance due')
            && str_contains(implode(' ', $mail->document->paragraphs), '40.00'));
        $this->assertNotNull($booking->refresh()->balance_reminded_at);

        // The next run five minutes later sends nothing more.
        $this->assertSame(0, app(BalanceReminders::class)->run(Carbon::parse('2026-11-18 09:10:00')));
        Mail::assertSent(CustomerDocumentMail::class, 1);
    }

    public function test_no_reminder_when_paid_in_full_or_not_from_the_website(): void
    {
        $full = $this->convert($this->webTrip(['amount_paid' => 50.0]));
        $this->assertSame(0.0, $full->amountOwed());

        // A desk booking owing money is not reminded.
        $desk = LimoBooking::query()->create(['customer_id' => LimoCustomer::query()->create(['name' => 'Desk', 'email' => 'desk@example.com'])->id, 'advance' => 5]);
        $desk->legs()->create(['status' => 'queue', 'start_at' => '2026-11-18 10:00:00', 'rate' => 30, 'net_amount' => 30, 'line_total' => 30]);
        $desk->recalcTotal();
        $desk->save();

        Mail::fake();
        $this->assertSame(0, app(BalanceReminders::class)->run(Carbon::parse('2026-11-18 09:30:00')));
        Mail::assertNothingSent();
    }

    public function test_paying_the_rest_before_the_hour_cancels_the_reminder(): void
    {
        $booking = $this->convert($this->webTrip());
        $booking->forceFill(['advance' => 50])->saveQuietly();
        Mail::fake();

        $this->assertSame(0, app(BalanceReminders::class)->run(Carbon::parse('2026-11-18 09:30:00')));
        Mail::assertNothingSent();
    }

    public function test_a_booking_made_inside_the_hour_gets_no_second_email(): void
    {
        Carbon::setTestNow('2026-11-18 09:20:00');
        $this->convert($this->webTrip());
        Mail::fake();

        $this->assertSame(0, app(BalanceReminders::class)->run(Carbon::parse('2026-11-18 09:25:00')));
        Mail::assertNothingSent();
    }

    public function test_switched_off_customer_emails_send_no_reminder(): void
    {
        $this->convert($this->webTrip());
        Setting::set('mail.customer_documents', false);
        Mail::fake();

        app(BalanceReminders::class)->run(Carbon::parse('2026-11-18 09:30:00'));
        Mail::assertNothingSent();
    }
}
