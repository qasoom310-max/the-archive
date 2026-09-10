<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Settings\Setting;
use App\Erp\Settings\SettingManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The band across the foot of every customer document. It reads THIS
 * database's settings, so each business prints its own contact details.
 */
final class DocumentFooterTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Every printable page that must carry the band. The documents a customer
     * is handed, and the reports and list exports printed off the screens —
     * anything on paper says who we are and how to reach us.
     */
    private const DOCUMENTS = [
        // The documents a customer receives.
        'Modules/Rental/resources/views/agreement-pdf.blade.php',
        'Modules/Limousine/resources/views/combined-invoice-pdf.blade.php',
        'Modules/Limousine/resources/views/coupon-voucher-pdf.blade.php',
        'Modules/Limousine/resources/views/invoice-pdf.blade.php',
        'Modules/Limousine/resources/views/invoices-batch-pdf.blade.php',
        'Modules/Limousine/resources/views/quotation-pdf.blade.php',
        'Modules/Limousine/resources/views/receipt-pdf.blade.php',
        'Modules/Limousine/resources/views/service-order-pdf.blade.php',
        'Modules/Limousine/resources/views/statement-pdf.blade.php',
        // Every list Print / PDF in the app renders through this one view.
        'resources/views/exports/list-print.blade.php',
        'Modules/Limousine/resources/views/queue-print.blade.php',
        // Reports.
        'Modules/Purchases/resources/views/reorder-pdf.blade.php',
        'Modules/Pos/resources/views/daily-report-pdf.blade.php',
        'resources/views/pdf/payslip.blade.php',
        'Modules/Pos/resources/views/stock-report-print.blade.php',
    ];

    /**
     * Pages a browser prints rather than DomPDF, so they have no `@page`
     * rule to check and lay the band out in the flow instead.
     */
    private const BROWSER_PRINTED = [
        'Modules/Pos/resources/views/stock-report-print.blade.php',
    ];

    private function configure(string $phone, string $alt, string $address): void
    {
        Setting::set('company.name', 'Wanaan Car Rental W.L.L');
        Setting::set('company.phone', $phone);
        Setting::set('company.phone_alt', $alt);
        Setting::set('company.address', $address);
        app(SettingManager::class)->flush();
    }

    private function footer(): string
    {
        return Blade::render('<x-document-footer />');
    }

    public function test_it_prints_the_company_the_numbers_and_the_address(): void
    {
        $this->configure('+973 17474949', '+973 39991869, +973 39991830', 'Shop 4, Building 18, Road 4101, Block 341, Juffair, Bahrain');
        Setting::set('company.email', 'info@wanaan-bh.com');
        app(SettingManager::class)->flush();

        $html = $this->footer();

        $this->assertStringContainsString('Wanaan Car Rental W.L.L', $html);
        $this->assertStringContainsString('+973 17474949', $html);
        $this->assertStringContainsString('+973 39991869', $html);
        $this->assertStringContainsString('+973 39991830', $html);
        $this->assertStringContainsString('Shop 4, Building 18, Road 4101, Block 341, Juffair, Bahrain', $html);
        $this->assertStringContainsString('info@wanaan-bh.com', $html);
    }

    public function test_the_hotline_comes_first_and_the_rest_follow(): void
    {
        $this->configure('+973 17474949', '+973 39991869, +973 39991830', 'Juffair');

        $html = $this->footer();
        $this->assertLessThan(
            strpos($html, '+973 39991869') ?: PHP_INT_MAX,
            strpos($html, '+973 17474949') ?: PHP_INT_MAX,
            'The hotline is the number to call first, so it prints first.',
        );
    }

    public function test_further_numbers_may_be_separated_however_the_admin_typed_them(): void
    {
        $this->configure('111', '222 ; 333 / 444, 555', 'Juffair');

        $html = $this->footer();
        foreach (['111', '222', '333', '444', '555'] as $number) {
            $this->assertStringContainsString($number, $html);
        }
    }

    public function test_a_database_with_no_details_prints_no_empty_band(): void
    {
        // Otherwise every other business on the system gets a blank grey bar.
        Setting::set('company.name', '');
        Setting::set('company.phone', '');
        Setting::set('company.phone_alt', '');
        Setting::set('company.address', '');
        Setting::set('company.email', '');
        Setting::set('company.website', '');
        app(SettingManager::class)->flush();

        $this->assertStringNotContainsString('doc-footer', $this->footer());
    }

    public function test_it_prints_the_trading_registrations(): void
    {
        // A customer's accounts department needs both off the paperwork: the
        // VAT number to reclaim the tax, the CR number to file the document
        // against a registered trader.
        $this->configure('+973 17474949', '', 'Juffair');
        Setting::set('company.vat_number', '220015215500002');
        Setting::set('company.cr_number', '135164-1');
        app(SettingManager::class)->flush();

        $html = $this->footer();

        $this->assertStringContainsString('VAT No.', $html);
        $this->assertStringContainsString('220015215500002', $html);
        $this->assertStringContainsString('CR No.', $html);
        $this->assertStringContainsString('135164-1', $html);
    }

    public function test_a_registration_on_its_own_is_enough_to_print_the_band(): void
    {
        Setting::set('company.name', 'Wanaan Car Rental W.L.L');
        Setting::set('company.phone', '');
        Setting::set('company.phone_alt', '');
        Setting::set('company.address', '');
        Setting::set('company.email', '');
        Setting::set('company.website', '');
        Setting::set('company.vat_number', '220015215500002');
        Setting::set('company.cr_number', '');
        app(SettingManager::class)->flush();

        $this->assertStringContainsString('220015215500002', $this->footer());
    }

    public function test_a_name_on_its_own_is_not_worth_a_band(): void
    {
        // Every document already prints the company name in its header, and a
        // database nobody has configured still answers "OpenERP" — which would
        // put a stranger's name on the foot of a real customer's invoice.
        Setting::set('company.name', 'OpenERP');
        Setting::set('company.phone', '');
        Setting::set('company.phone_alt', '');
        Setting::set('company.address', '');
        Setting::set('company.email', '');
        Setting::set('company.website', '');
        app(SettingManager::class)->flush();

        $this->assertStringNotContainsString('doc-footer', $this->footer());
    }

    public function test_one_contact_detail_is_enough_to_print_it(): void
    {
        Setting::set('company.name', 'Hashtag Limo');
        Setting::set('company.phone', '');
        Setting::set('company.phone_alt', '');
        Setting::set('company.address', 'Manama, Bahrain');
        Setting::set('company.email', '');
        Setting::set('company.website', '');
        app(SettingManager::class)->flush();

        $html = $this->footer();
        $this->assertStringContainsString('doc-footer', $html);
        $this->assertStringContainsString('Hashtag Limo', $html);
        $this->assertStringContainsString('Manama, Bahrain', $html);
    }

    public function test_it_repeats_on_every_page_and_stays_out_of_the_body(): void
    {
        // `position: fixed` is what makes DomPDF repeat it per page; the host
        // documents reserve room for it in their @page bottom margin.
        $this->configure('+973 17474949', '', 'Juffair');

        $html = $this->footer();
        $this->assertStringContainsString('position: fixed', $html);
        $this->assertStringContainsString('bottom: -50px', $html);
    }

    public function test_every_customer_document_carries_the_band_and_reserves_room_for_it(): void
    {
        foreach (self::DOCUMENTS as $relative) {
            $source = (string) file_get_contents(base_path($relative));

            // The list views pass :fixed="$forPdf", so match the tag opening.
            $this->assertStringContainsString('<x-document-footer', $source, $relative . ' must print the footer band.');

            if (in_array($relative, self::BROWSER_PRINTED, true)) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/@page \{ margin: [^;]*60px[^;]*; \}/',
                $source,
                $relative . ' must reserve the 60px bottom margin the fixed band needs.',
            );
        }
    }

    public function test_a_list_export_prints_the_band_under_its_rows(): void
    {
        // The owner printed a quotations list and found no footer: every list
        // Print / PDF in the app comes out of this one shared view.
        $this->configure('+973 17474949', '', 'Juffair, Bahrain');

        $html = view('exports.list-print', [
            'rows' => [['ref' => 'QT/00558']],
            'headings' => ['ref' => 'Reference'],
            'title' => 'Quotations',
            'forPdf' => true,
        ])->render();

        $this->assertStringContainsString('QT/00558', $html);
        $this->assertStringContainsString('+973 17474949', $html);
        $this->assertStringContainsString('Juffair, Bahrain', $html);
    }

    public function test_the_browser_print_view_lays_the_band_out_in_the_flow(): void
    {
        // Browsers disagree about repeating a fixed element per page, and on
        // screen a negative offset would put it below the window entirely.
        $this->configure('+973 17474949', '', 'Juffair');

        $forPdf = view('exports.list-print', [
            'rows' => [], 'headings' => [], 'title' => 'Quotations', 'forPdf' => true,
        ])->render();
        $forBrowser = view('exports.list-print', [
            'rows' => [], 'headings' => [], 'title' => 'Quotations', 'forPdf' => false,
        ])->render();

        $this->assertStringContainsString('doc-footer-fixed', $forPdf);
        $this->assertStringContainsString('doc-footer-flow', $forBrowser);
        $this->assertStringNotContainsString('doc-footer-fixed"', $forBrowser);
    }

    public function test_the_thermal_receipt_slip_is_left_alone(): void
    {
        // It is a 360px-wide till roll that already prints the shop's phone at
        // the top; a full-width grey band does not belong on it.
        $source = (string) file_get_contents(base_path('Modules/Pos/resources/views/receipt-pdf.blade.php'));
        $this->assertStringNotContainsString('<x-document-footer', $source);

        // The rental agreement's print overlay lands on pre-printed
        // stationery that carries its own footer; ours would sit on top.
        $overlay = (string) file_get_contents(base_path('Modules/Rental/resources/views/agreement-print.blade.php'));
        $this->assertStringNotContainsString('<x-document-footer', $overlay);
    }

    public function test_the_old_hardcoded_address_is_gone_from_the_service_order(): void
    {
        // It said Shop 2082, Road 5669 — the office has moved, and an address
        // baked into a shared module would print on every other business too.
        $source = (string) file_get_contents(base_path('Modules/Limousine/resources/views/service-order-pdf.blade.php'));

        $this->assertStringNotContainsString('Shop 2082', $source);
        $this->assertStringNotContainsString('17474949', $source);
    }
}
