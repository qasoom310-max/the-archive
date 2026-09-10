<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Coupons;
use Modules\Limousine\Mail\CouponVoucherMail;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCoupon;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\CouponSender;
use Modules\Limousine\Services\CouponVoucherPdf;
use Modules\Limousine\Services\TripCancellation;
use Tests\TestCase;

/**
 * The customer's copy of their credit: a voucher to download, and an email that
 * carries it.
 *
 * The credit was otherwise only a row in our system — the customer paid, got no
 * journey and no money back, and held nothing to show for it.
 */
final class LimoCouponVoucherTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');

        // Module routes register at boot, gated on the module already being
        // installed — which it was not until the line above. Load them so the
        // download can be fetched at its real URL.
        Route::middleware('web')->group(base_path('Modules/Limousine/routes/web.php'));
        Route::getRoutes()->refreshNameLookups();
    }

    /** A real coupon, made the way one is really made: a late cancellation. */
    private function coupon(?string $email = 'helen@example.com'): LimoCoupon
    {
        $customer = LimoCustomer::query()->create([
            'name' => 'Helen Friberg',
            'phone' => '+973 1234',
            'email' => $email,
        ]);

        $booking = LimoBooking::query()->create([
            'reference' => 'BK/00001',
            'customer_id' => $customer->id,
            'pickup_at' => now()->addHours(10),
            'status' => LimoBooking::STATUS_ACTIVE,
            'advance' => 63,
            'payment_status' => LimoBooking::PAYMENT_PAID,
        ]);

        $leg = LimoLeg::query()->create([
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 0,
            'reference' => '10006',
            'status' => LimoLeg::STATUS_ACTIVE,
            'start_at' => now()->addHours(10),
            'from_location' => 'Hotel',
            'to_location' => 'Bahrain Airport',
            'rate' => 63, 'net_amount' => 63,
        ]);

        $booking->recalcTotal();
        $booking->save();

        $coupon = app(TripCancellation::class)->cancel($leg->refresh())['coupon'];
        $this->assertNotNull($coupon);

        return $coupon;
    }

    public function test_the_voucher_carries_the_code_and_the_balance(): void
    {
        $coupon = $this->coupon();

        $data = app(CouponVoucherPdf::class)->viewData($coupon);

        $this->assertSame($coupon->code, $data['code']);
        $this->assertSame('Helen Friberg', $data['customerName']);
        $this->assertSame(63.0, $data['issued']);
        $this->assertSame(63.0, $data['remaining']);
        $this->assertSame('10006', $data['fromTrip']);
    }

    public function test_the_voucher_renders_as_a_pdf(): void
    {
        $coupon = $this->coupon();

        $pdf = app(CouponVoucherPdf::class)->render($coupon);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('.pdf', app(CouponVoucherPdf::class)->filename($coupon));
    }

    public function test_it_downloads(): void
    {
        $coupon = $this->coupon();

        $this->get('/app/limousine/coupon/' . $coupon->id . '/pdf')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="coupon-' . $coupon->code . '.pdf"');
    }

    /** The office should not have to retype an address we already hold. */
    public function test_the_send_dialog_offers_the_customers_address(): void
    {
        $coupon = $this->coupon();

        Livewire::test(Coupons::class)
            ->call('openSend', $coupon->id)
            ->assertSet('sendingId', $coupon->id)
            ->assertSet('sendEmail', 'helen@example.com');
    }

    /** …and must not invent one when we hold none. */
    public function test_no_address_on_file_leaves_the_box_empty(): void
    {
        $coupon = $this->coupon(email: null);

        Livewire::test(Coupons::class)
            ->call('openSend', $coupon->id)
            ->assertSet('sendEmail', '');
    }

    public function test_sending_mails_the_voucher_and_records_it(): void
    {
        Mail::fake();
        $coupon = $this->coupon();

        Livewire::test(Coupons::class)
            ->call('openSend', $coupon->id)
            ->call('sendCoupon')
            ->assertHasNoErrors()
            ->assertSet('sendingId', null);

        Mail::assertSent(CouponVoucherMail::class, fn ($mail): bool => $mail->hasTo('helen@example.com'));

        $fresh = $coupon->fresh();
        $this->assertNotNull($fresh?->sent_at);
        $this->assertSame('helen@example.com', $fresh?->sent_to);
    }

    /**
     * Mail::fake() never renders the body, so a broken email TEMPLATE can
     * pass every test above and still 500 the moment someone presses Send.
     * This template uses <x-mail::message>/<x-mail::panel>, which only
     * resolve when the Mailable is wired as `Content(markdown: ...)`; wired
     * as `view:` it throws "No hint path defined for [mail]" in production.
     */
    public function test_the_email_body_actually_renders(): void
    {
        $coupon = $this->coupon();

        $html = (new CouponVoucherMail(
            coupon: $coupon,
            companyName: 'Wanaan Car Rental',
            remaining: 63.0,
            pdf: '%PDF-fake',
            filename: 'coupon.pdf',
        ))->render();

        $this->assertStringContainsString($coupon->code, $html);
        $this->assertStringContainsString('Wanaan Car Rental', $html);
    }

    /** The address is editable, because the one on file is often the wrong one. */
    public function test_a_corrected_address_is_used_and_remembered(): void
    {
        Mail::fake();
        $coupon = $this->coupon(email: null);

        Livewire::test(Coupons::class)
            ->call('openSend', $coupon->id)
            ->set('sendEmail', 'accounts@company.com')
            ->call('sendCoupon')
            ->assertHasNoErrors();

        Mail::assertSent(CouponVoucherMail::class, fn ($mail): bool => $mail->hasTo('accounts@company.com'));

        // Learned, so the next coupon for this customer already knows it.
        $this->assertSame('accounts@company.com', $coupon->fresh()?->customer?->email);
    }

    public function test_a_bad_address_is_refused(): void
    {
        Mail::fake();
        $coupon = $this->coupon();

        Livewire::test(Coupons::class)
            ->call('openSend', $coupon->id)
            ->set('sendEmail', 'not-an-address')
            ->call('sendCoupon')
            ->assertHasErrors('sendEmail');

        Mail::assertNothingSent();
        $this->assertNull($coupon->fresh()?->sent_at);
    }

    /** The voucher rides along as a file the customer can keep. */
    public function test_the_pdf_is_attached(): void
    {
        $coupon = $this->coupon();

        $pdf = app(CouponVoucherPdf::class);
        $mail = new CouponVoucherMail(
            coupon: $coupon,
            companyName: 'Wanaan',
            remaining: $coupon->remaining(),
            pdf: $pdf->render($coupon),
            filename: $pdf->filename($coupon),
        );

        $attachments = $mail->attachments();
        $this->assertCount(1, $attachments);
        $this->assertSame($pdf->filename($coupon), $attachments[0]->as);

        // The code and the balance are in the MESSAGE too, not only the file:
        // most people read them off the screen without opening the attachment.
        $content = $mail->content();
        $this->assertSame('limousine::coupon-voucher-email', $content->markdown);
        $this->assertSame($coupon->code, $content->with['coupon']->code);
        $this->assertSame(63.0, $content->with['remaining']);

        // And the subject names the coupon, so it is findable in a mailbox.
        $this->assertStringContainsString($coupon->code, $mail->envelope()->subject);
    }

    public function test_sending_again_says_so(): void
    {
        Mail::fake();
        $coupon = $this->coupon();

        $sender = app(CouponSender::class);
        $first = $sender->send($coupon, 'helen@example.com');
        $second = $sender->send($coupon->fresh(), 'helen@example.com');

        $this->assertFalse($first['resent']);
        $this->assertTrue($second['resent']);
    }
}
