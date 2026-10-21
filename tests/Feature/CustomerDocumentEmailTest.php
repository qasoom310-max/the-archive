<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Mail\CustomerMailer;
use App\Erp\Modules\ModuleManager;
use App\Erp\Settings\Setting;
use App\Mail\CustomerDocumentMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Services\CustomerDocumentEmails;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalInvoice;
use Modules\Rental\Models\RentalQuotation;
use Modules\Rental\Models\RentalReceipt;
use Modules\Rental\Services\RentalDocumentEmails;
use Tests\TestCase;

/**
 * Quotations, invoices and receipts are emailed to the customer as they are
 * made: a quote on its own; an invoice with its receipts when something was
 * paid; a later payment as its receipt plus the invoice. Every email carries
 * the terms PDF, and nothing is sent unless the database switched it on.
 */
final class CustomerDocumentEmailTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        Mail::fake();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'name' => 'Qassim']));
        app(ModuleManager::class)->install('limousine');

        Setting::set('company.name', 'Wanaan Car Rental W.L.L');
        Setting::set('mail.customer_documents', true);
        Setting::set('mail.from_address', 'Erp@wanaan-bh.com');
        Setting::set('company.customer_terms', "# Terms & Conditions\n\n- Cancel 24 hours ahead for a full refund.");
    }

    private function customer(?string $email = 'helen@example.com'): LimoCustomer
    {
        return LimoCustomer::query()->create(['name' => 'Helen Friberg', 'phone' => '39000000', 'email' => $email]);
    }

    private function bookingForm(LimoCustomer $customer, string $advance): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(BookingForm::class)
            ->set('customer_id', $customer->id)
            ->set('requested_by', 'Office')
            ->set('legs.0.from_location', 'Hotel')
            ->set('legs.0.to_location', 'Airport')
            ->set('legs.0.estimated_hours', '2')
            ->set('legs.0.start_at', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('legs.0.rate', '45')
            ->set('legs.0.car_details', 'Sedan')
            ->set('advance', $advance);
    }

    /** @return list<string> */
    private function attachmentNames(CustomerDocumentMail $mail): array
    {
        return array_map(static fn (array $f): string => $f['name'], $mail->attachmentFiles);
    }

    public function test_an_unpaid_booking_emails_just_the_invoice_and_the_terms(): void
    {
        $this->bookingForm($this->customer(), '')->call('save')->assertHasNoErrors();

        Mail::assertSent(CustomerDocumentMail::class, 1);
        Mail::assertSent(CustomerDocumentMail::class, function (CustomerDocumentMail $mail): bool {
            $names = $this->attachmentNames($mail);

            return $mail->hasTo('helen@example.com')
                && count($names) === 2
                && str_starts_with($names[0], 'invoice-')
                && $names[1] === 'Terms-and-Conditions.pdf'
                && $mail->fromAddress === 'Erp@wanaan-bh.com'
                && str_contains($mail->document->subject, 'Invoice');
        });
    }

    public function test_a_part_paid_booking_emails_the_invoice_and_the_receipt(): void
    {
        $this->bookingForm($this->customer(), '20')->call('save')->assertHasNoErrors();

        Mail::assertSent(CustomerDocumentMail::class, 1);
        Mail::assertSent(CustomerDocumentMail::class, function (CustomerDocumentMail $mail): bool {
            $names = $this->attachmentNames($mail);

            return count($names) === 3
                && str_starts_with($names[0], 'invoice-')
                && str_starts_with($names[1], 'receipt-')
                && $names[2] === 'Terms-and-Conditions.pdf'
                && str_contains(implode(' ', $mail->document->paragraphs), 'part of the payment');
        });
    }

    public function test_a_fully_paid_booking_says_so(): void
    {
        $this->bookingForm($this->customer(), '45')->call('save')->assertHasNoErrors();

        Mail::assertSent(CustomerDocumentMail::class, static fn (CustomerDocumentMail $mail): bool => count($mail->attachmentFiles) === 3
            && str_contains(implode(' ', $mail->document->paragraphs), 'paid in full'));
    }

    public function test_money_taken_later_emails_the_receipt_with_the_invoice(): void
    {
        $this->bookingForm($this->customer(), '')->call('save')->assertHasNoErrors();
        Mail::fake(); // forget the invoice email from the booking

        Livewire::test(Bookings::class)
            ->call('openCollect', LimoLeg::query()->sole()->id)
            ->set('collectAmount', '25')
            ->call('saveCollect')
            ->assertHasNoErrors();

        Mail::assertSent(CustomerDocumentMail::class, function (CustomerDocumentMail $mail): bool {
            $names = $this->attachmentNames($mail);

            return str_contains($mail->document->subject, 'Receipt')
                && str_starts_with($names[0], 'receipt-')
                && str_starts_with($names[1], 'invoice-')
                && $names[2] === 'Terms-and-Conditions.pdf';
        });
    }

    public function test_a_new_quotation_is_emailed(): void
    {
        $quote = LimoQuotation::query()->create([
            'customer_id' => $this->customer()->id,
            'quote_date' => Carbon::today(),
            'pickup_at' => now()->addDay(),
            'fare' => 60,
        ]);

        app(CustomerDocumentEmails::class)->quotationCreated((int) $quote->id);

        Mail::assertSent(CustomerDocumentMail::class, function (CustomerDocumentMail $mail): bool {
            $names = $this->attachmentNames($mail);

            return str_contains($mail->document->subject, 'Quotation')
                && str_starts_with($names[0], 'quotation-')
                && $names[1] === 'Terms-and-Conditions.pdf';
        });
    }

    public function test_nothing_is_sent_when_switched_off(): void
    {
        Setting::set('mail.customer_documents', false);

        $this->bookingForm($this->customer(), '20')->call('save')->assertHasNoErrors();

        Mail::assertNothingSent();
    }

    public function test_a_customer_without_an_email_gets_nothing_and_the_booking_still_saves(): void
    {
        $this->bookingForm($this->customer(null), '20')->call('save')->assertHasNoErrors();

        Mail::assertNothingSent();
        $this->assertSame(1, LimoBooking::query()->count());
    }

    public function test_no_terms_written_means_no_terms_pdf(): void
    {
        Setting::set('company.customer_terms', '');

        $this->bookingForm($this->customer(), '')->call('save')->assertHasNoErrors();

        Mail::assertSent(CustomerDocumentMail::class, fn (CustomerDocumentMail $mail): bool => $this->attachmentNames($mail) !== []
            && ! in_array('Terms-and-Conditions.pdf', $this->attachmentNames($mail), true));
    }

    public function test_the_email_and_the_terms_pdf_render(): void
    {
        $terms = app(CustomerMailer::class)->termsPdf();
        $this->assertNotNull($terms);
        $this->assertStringStartsWith('%PDF', $terms['data']);

        $this->bookingForm($this->customer(), '20')->call('save')->assertHasNoErrors();

        Mail::assertSent(CustomerDocumentMail::class, static function (CustomerDocumentMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'Dear Helen Friberg,')
                && str_contains($html, 'Balance due')
                && str_contains($html, 'Wanaan Car Rental W.L.L');
        });
    }

    /* ── Rent A Car ─────────────────────────────────────────────────────── */

    public function test_rental_documents_are_emailed_the_same_way(): void
    {
        app(ModuleManager::class)->install('rental');
        $customer = RentalCustomer::query()->create(['name' => 'Ali Hasan', 'phone' => '36000000', 'email' => 'ali@example.com']);

        $quote = RentalQuotation::query()->create([
            'customer_id' => $customer->id,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today()->addDays(3),
            'rate' => 20, 'days' => 3, 'subtotal' => 60, 'total' => 60,
        ]);
        app(RentalDocumentEmails::class)->quotationCreated((int) $quote->id);

        $invoice = RentalInvoice::query()->create([
            'customer_id' => $customer->id, 'issue_date' => Carbon::today(), 'subtotal' => 60, 'total' => 60,
        ]);
        app(RentalDocumentEmails::class)->invoiceIssued((int) $invoice->id);

        $receipt = RentalReceipt::query()->create([
            'invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'date' => Carbon::today(), 'amount' => 30, 'method' => 'cash',
        ]);
        app(RentalDocumentEmails::class)->paymentReceived((int) $receipt->id);

        Mail::assertSent(CustomerDocumentMail::class, 3);
        Mail::assertSent(CustomerDocumentMail::class, fn (CustomerDocumentMail $mail): bool => $mail->hasTo('ali@example.com')
            && str_contains($mail->document->subject, 'Quotation')
            && count($mail->attachmentFiles) === 2);
        Mail::assertSent(CustomerDocumentMail::class, fn (CustomerDocumentMail $mail): bool => str_contains($mail->document->subject, 'Invoice')
            && count($mail->attachmentFiles) === 2);
        Mail::assertSent(CustomerDocumentMail::class, fn (CustomerDocumentMail $mail): bool => str_contains($mail->document->subject, 'Receipt')
            && count($mail->attachmentFiles) === 3);
    }
}
