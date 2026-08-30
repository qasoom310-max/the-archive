<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Mail\ServiceOrderCompanyMail;
use Modules\Limousine\Mail\ServiceOrderMail;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\ServiceOrderSender;
use Tests\TestCase;

/**
 * The Service Order: the per-trip sheet, and the customer's signature on it —
 * the proof the driver arrived and the service was used.
 *
 * The signing page is PUBLIC (a customer has no account), so the URL signature
 * is the only thing standing between one customer and another's trip. Those
 * guarantees are what these tests are mostly about.
 */
final class LimoServiceOrderTest extends TestCase
{
    use DatabaseMigrations;

    /** A 1×1 transparent PNG as a canvas would hand over. */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');

        // Module routes are registered at boot, gated on the module already
        // being installed — which it isn't until the line above. Load them now
        // so this test can exercise the real URLs (and the signed-link rules
        // that are the whole point of the feature).
        Route::middleware('web')->group(base_path('Modules/Limousine/routes/web.php'));
        // Adding routes after boot leaves the name lookup table stale, so
        // route() / signedRoute() can't find them from the test body (a real
        // request rebuilds it, which is why only direct calls failed).
        Route::getRoutes()->refreshNameLookups();
    }

    private function leg(?string $email = 'helen@example.test', string $type = 'individual', ?string $serviceEmail = null): LimoLeg
    {
        $customer = LimoCustomer::query()->create([
            'name' => 'Helen Friberg', 'phone' => '39211006',
            'email' => $email, 'type' => $type, 'service_email' => $serviceEmail,
        ]);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00001',
            'customer_id' => $customer->id,
            'pickup_at' => '2026-04-26 21:15:00',
            'status' => LimoBooking::STATUS_ACTIVE,
        ]);

        return LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 1,
            'from_location' => 'Bahrain airport GF509',
            'to_location' => 'Amwaj',
            'start_at' => '2026-04-26 21:15:00',
            'vehicle' => '111705 - FORD TERRITORY',
            'driver' => 'sohail',
            'rate' => 22.5,
            'net_amount' => 22.5,
        ]);
    }

    public function test_staff_can_download_the_service_order_pdf(): void
    {
        $leg = $this->leg();

        $res = $this->get('/app/limousine/service-order/' . $leg->id);

        $res->assertOk();
        $res->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $res->getContent());
    }

    public function test_the_signing_page_opens_from_a_signed_link_and_shows_the_trip(): void
    {
        $leg = $this->leg();

        $this->get(app(ServiceOrderSender::class)->signUrl($leg))
            ->assertOk()
            ->assertSee('Helen Friberg')
            ->assertSee('Bahrain airport GF509')
            ->assertSee('Amwaj');
    }

    /**
     * The whole security model: without a valid signature the link is refused,
     * so a customer cannot walk the leg id to somebody else's trip.
     */
    public function test_an_unsigned_or_tampered_link_is_refused(): void
    {
        $leg = $this->leg();
        $other = $this->leg('other@example.test');

        // No signature at all.
        $this->get('/service-order/' . $leg->id . '/sign')->assertForbidden();

        // A real link, with the leg id swapped for another trip.
        $tampered = str_replace(
            '/service-order/' . $leg->id . '/sign',
            '/service-order/' . $other->id . '/sign',
            app(ServiceOrderSender::class)->signUrl($leg),
        );
        $this->get($tampered)->assertForbidden();
    }

    public function test_an_expired_link_is_refused(): void
    {
        $leg = $this->leg();

        $url = URL::temporarySignedRoute('limousine.service_order.sign', now()->subMinute(), ['leg' => $leg->id]);

        $this->get($url)->assertForbidden();
    }

    public function test_signing_stores_the_signature_with_who_and_when(): void
    {
        Storage::fake('public');
        $leg = $this->leg();
        $url = app(ServiceOrderSender::class)->signUrl($leg);

        $this->post($url, ['signed_name' => 'Helen Friberg', 'signature' => self::PNG])
            ->assertRedirect();

        $leg->refresh();
        $this->assertTrue($leg->isSigned());
        $this->assertSame('Helen Friberg', $leg->signed_name);
        $this->assertNotNull($leg->signed_at);
        $this->assertNotNull($leg->signed_ip);
        Storage::disk('public')->assertExists((string) $leg->signature_path);
    }

    public function test_a_signature_that_is_not_a_png_is_rejected(): void
    {
        Storage::fake('public');
        $leg = $this->leg();
        $url = app(ServiceOrderSender::class)->signUrl($leg);

        // Right prefix, but the bytes are not a PNG — public input is not taken
        // at its own word.
        $this->post($url, [
            'signed_name' => 'Helen',
            'signature' => 'data:image/png;base64,' . base64_encode('<?php echo "not an image";'),
        ])->assertSessionHasErrors('signature');

        $this->assertFalse($leg->fresh()?->isSigned());
    }

    public function test_an_existing_signature_is_never_overwritten(): void
    {
        Storage::fake('public');
        $leg = $this->leg();
        $url = app(ServiceOrderSender::class)->signUrl($leg);

        $this->post($url, ['signed_name' => 'Helen Friberg', 'signature' => self::PNG]);
        $first = $leg->fresh();
        $this->assertNotNull($first);

        // Somebody re-opens the link later and signs again.
        $this->post($url, ['signed_name' => 'Someone Else', 'signature' => self::PNG]);

        $this->assertSame('Helen Friberg', $leg->fresh()?->signed_name);
        $this->assertSame($first->signature_path, $leg->fresh()?->signature_path);
    }

    public function test_the_signed_page_shows_the_proof_instead_of_a_fresh_pad(): void
    {
        Storage::fake('public');
        $leg = $this->leg();
        $url = app(ServiceOrderSender::class)->signUrl($leg);
        $this->post($url, ['signed_name' => 'Helen Friberg', 'signature' => self::PNG]);

        $this->get($url)
            ->assertOk()
            ->assertSee('Helen Friberg')
            ->assertDontSee('sig-pad');   // the canvas is gone
    }

    public function test_the_office_can_email_the_signing_link(): void
    {
        Mail::fake();
        $leg = $this->leg();

        Livewire::test(Bookings::class)->call('sendServiceOrder', $leg->id);

        Mail::assertSent(ServiceOrderMail::class);
        $this->assertNotNull($leg->fresh()?->service_order_sent_at);
    }

    public function test_a_customer_with_no_email_is_reported_not_silently_skipped(): void
    {
        Mail::fake();
        $leg = $this->leg(null);

        Livewire::test(Bookings::class)->call('sendServiceOrder', $leg->id);

        Mail::assertNothingSent();
        $this->assertNull($leg->fresh()?->service_order_sent_at);
    }

    /**
     * A company booked the car for its guest — it was not in it, so it cannot
     * sign for the journey. It gets told the driver arrived instead, and never
     * a signing link.
     */
    public function test_a_company_is_notified_rather_than_asked_to_sign(): void
    {
        Mail::fake();
        $leg = $this->leg('accounts@acme.test', 'company', 'ops@acme.test');

        Livewire::test(Bookings::class)->call('sendServiceOrder', $leg->id);

        Mail::assertNotSent(ServiceOrderMail::class);
        // Straight to the SERVICE address — the people who follow the trip, not
        // the ones who placed the booking.
        Mail::assertSent(ServiceOrderCompanyMail::class, fn ($mail): bool => $mail->hasTo('ops@acme.test'));
        $this->assertNotNull($leg->fresh()?->service_order_sent_at);
    }

    public function test_a_company_without_a_service_address_falls_back_to_the_general_one(): void
    {
        Mail::fake();
        $leg = $this->leg('accounts@acme.test', 'company', null);

        Livewire::test(Bookings::class)->call('sendServiceOrder', $leg->id);

        Mail::assertSent(ServiceOrderCompanyMail::class, fn ($mail): bool => $mail->hasTo('accounts@acme.test'));
    }

    public function test_an_individual_still_gets_the_signing_link(): void
    {
        Mail::fake();
        $leg = $this->leg('helen@example.test', 'individual');

        Livewire::test(Bookings::class)->call('sendServiceOrder', $leg->id);

        Mail::assertSent(ServiceOrderMail::class, fn ($mail): bool => $mail->hasTo('helen@example.test'));
        Mail::assertNotSent(ServiceOrderCompanyMail::class);
    }

    public function test_the_queue_shows_it_was_sent_and_offers_a_resend(): void
    {
        Mail::fake();
        $leg = $this->leg();

        Livewire::test(Bookings::class)
            ->call('sendServiceOrder', $leg->id)
            ->assertSee('Sent to sign')
            ->assertSee('Resend');

        // Resending is allowed — a mail can bounce or a link can lapse.
        Livewire::test(Bookings::class)->call('sendServiceOrder', $leg->id);
        Mail::assertSent(ServiceOrderMail::class, 2);
    }
}
