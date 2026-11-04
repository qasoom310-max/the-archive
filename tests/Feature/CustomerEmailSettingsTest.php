<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Mail\EmailTemplates;
use App\Erp\Modules\ModuleManager;
use App\Erp\Settings\Setting;
use App\Livewire\Settings\CustomerEmailSettings;
use App\Mail\CustomerDocumentMail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Modules\Limousine\Mail\QuotationMail;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Services\CustomerDocumentEmails;
use Tests\TestCase;

/**
 * Settings → Customer emails: the look, signature and wording of every email
 * a customer receives, edited per database, with blank meaning "the default".
 */
final class CustomerEmailSettingsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'email' => 'owner@example.com']));
        app(ModuleManager::class)->install('limousine');

        Setting::set('company.name', 'Wanaan Car Rental W.L.L');
        Setting::set('company.phone', '+973 17474949');
        Setting::set('company.website', '');
        Setting::set('mail.customer_documents', true);
        Setting::set('company.customer_terms', '');
    }

    private function invoice(): LimoInvoice
    {
        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg', 'phone' => '39000000', 'email' => 'helen@example.com']);

        return LimoInvoice::query()->create([
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'subtotal' => 100, 'total' => 100, 'amount_paid' => 0,
        ]);
    }

    public function test_an_admin_opens_the_page(): void
    {
        $this->get('/app/settings/customer-emails')
            ->assertOk()
            ->assertSee('Design &amp; signature', false)
            ->assertSee('Credit coupon');
    }

    public function test_it_is_administrator_only(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->get('/app/settings/customer-emails')->assertForbidden();
    }

    public function test_an_untouched_database_sends_the_default_wording(): void
    {
        $mail = app(EmailTemplates::class)->compose('invoice', ['reference' => 'INV/1', 'payment_state' => 'unpaid']);

        $this->assertSame('Wanaan Car Rental W.L.L — Invoice INV/1', $mail['subject']);
        $this->assertSame([
            'Thank you for choosing us. Please find your invoice attached.',
            'The amount due is shown below. Payment details are printed on the invoice.',
        ], $mail['intro']);
    }

    public function test_edited_wording_and_signature_reach_the_next_email(): void
    {
        Mail::fake();

        Livewire::test(CustomerEmailSettings::class)
            ->set('types.invoice.subject', 'Your bill {reference} from {company}')
            ->set('types.invoice.status_unpaid', 'Please pay {balance} within 7 days.')
            ->set('layout.signature', "Best wishes,\n**Accounts team**\n{phone}")
            ->set('layout.accent', '#0EA5E9')
            ->call('save')
            ->assertHasNoErrors();

        $invoice = $this->invoice();
        app(CustomerDocumentEmails::class)->invoiceIssued($invoice->id, now: true);

        Mail::assertSent(CustomerDocumentMail::class, function (CustomerDocumentMail $mail) use ($invoice): bool {
            $html = $mail->render();

            return $mail->document->subject === 'Your bill ' . $invoice->reference . ' from Wanaan Car Rental W.L.L'
                && str_contains($html, 'Please pay ' . \App\Erp\Money\Currencies::format(100.0) . ' within 7 days.')
                && str_contains($html, 'Best wishes,<br>')
                && str_contains($html, '<strong>Accounts team</strong>')
                && str_contains($html, '+973 17474949')
                && str_contains($html, '#0EA5E9');
        });
    }

    public function test_only_what_differs_from_the_default_is_stored(): void
    {
        Livewire::test(CustomerEmailSettings::class)
            ->set('types.quotation.heading', 'A quote for you')
            ->call('save');

        $stored = app(EmailTemplates::class)->stored();
        $this->assertSame(['heading' => 'A quote for you'], $stored['types']['quotation']);
        $this->assertSame([], $stored['layout']);
        $this->assertArrayNotHasKey('invoice', $stored['types']);
    }

    public function test_a_cleared_box_and_reset_go_back_to_the_default(): void
    {
        $page = Livewire::test(CustomerEmailSettings::class)
            ->set('types.quotation.heading', 'A quote for you')
            ->call('save')
            ->set('types.quotation.heading', '')
            ->call('save');

        $this->assertSame('Your quotation {reference}', app(EmailTemplates::class)->template('quotation')['heading']);

        $page->set('types.receipt.intro', 'Changed')->call('resetToDefault', 'receipt');
        $this->assertSame(
            app(EmailTemplates::class)->defaultFor('receipt', 'intro'),
            $page->get('types')['receipt']['intro'],
        );
    }

    public function test_the_send_buttons_use_the_same_template(): void
    {
        Livewire::test(CustomerEmailSettings::class)
            ->set('types.quotation.intro', 'Here is the price we discussed, {customer}.')
            ->call('save');

        $customer = LimoCustomer::query()->create(['name' => 'Helen Friberg', 'phone' => '39000000', 'email' => 'helen@example.com']);
        $quote = LimoQuotation::query()->create(['customer_id' => $customer->id, 'quote_date' => now()->toDateString(), 'fare' => 45]);

        $mail = new QuotationMail(quote: $quote, companyName: 'x', total: 45.0, pdf: '%PDF', filename: 'q.pdf');
        $html = $mail->render();

        $this->assertStringContainsString('Here is the price we discussed, Helen Friberg.', $html);
        $this->assertStringContainsString('Wanaan Car Rental W.L.L — Quotation ' . $quote->reference, $mail->envelope()->subject);
    }

    public function test_typed_html_is_shown_as_text_not_run(): void
    {
        Livewire::test(CustomerEmailSettings::class)
            ->set('types.quotation.intro', '<script>alert(1)</script> Hello')
            ->call('save');

        $html = app(EmailTemplates::class)->compose('quotation', [])['intro'][0];
        $rendered = EmailTemplates::html($html);

        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
    }

    public function test_a_bad_colour_is_refused(): void
    {
        Livewire::test(CustomerEmailSettings::class)
            ->set('layout.accent', 'red; background:url(x)')
            ->call('save')
            ->assertHasErrors('layout.accent');
    }

    public function test_a_footer_line_with_nothing_in_it_is_left_out(): void
    {
        // Website is blank, so "{phone} · {website}" reads as just the phone.
        $vars = app(EmailTemplates::class)->withCommon([]);
        $lines = EmailTemplates::lines(app(EmailTemplates::class)->fill("{phone} · {website}\n{address}", $vars));

        $this->assertSame(['+973 17474949'], $lines);
    }

    public function test_a_test_email_goes_to_the_given_address(): void
    {
        Mail::fake();

        Livewire::test(CustomerEmailSettings::class)
            ->call('openTab', 'coupon')
            ->set('testEmail', 'owner@example.com')
            ->call('sendTest')
            ->assertHasNoErrors()
            ->assertSee('Test email sent to owner@example.com.');

        Mail::assertSent(CustomerDocumentMail::class, fn (CustomerDocumentMail $mail): bool => $mail->hasTo('owner@example.com')
            && str_contains($mail->document->subject, 'Your credit coupon'));
    }

    public function test_the_switch_and_terms_moved_off_the_general_tab(): void
    {
        Livewire::test(CustomerEmailSettings::class)
            ->set('enabled', false)
            ->set('fromAddress', 'erp@wanaan-bh.com')
            ->call('save');

        $this->assertFalse(Setting::get('mail.customer_documents'));
        $this->assertSame('erp@wanaan-bh.com', Setting::get('mail.from_address'));

        $this->get('/app/settings')->assertOk()->assertDontSee('mail.customer_documents');
    }
}
