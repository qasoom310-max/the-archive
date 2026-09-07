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

    /** Every customer-facing document that must carry the band. */
    private const DOCUMENTS = [
        'Modules/Rental/resources/views/agreement-pdf.blade.php',
        'Modules/Limousine/resources/views/combined-invoice-pdf.blade.php',
        'Modules/Limousine/resources/views/coupon-voucher-pdf.blade.php',
        'Modules/Limousine/resources/views/invoice-pdf.blade.php',
        'Modules/Limousine/resources/views/quotation-pdf.blade.php',
        'Modules/Limousine/resources/views/receipt-pdf.blade.php',
        'Modules/Limousine/resources/views/service-order-pdf.blade.php',
        'Modules/Limousine/resources/views/statement-pdf.blade.php',
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

            $this->assertStringContainsString('<x-document-footer />', $source, $relative . ' must print the footer band.');
            $this->assertMatchesRegularExpression(
                '/@page \{ margin: [^;]*60px[^;]*; \}/',
                $source,
                $relative . ' must reserve the 60px bottom margin the fixed band needs.',
            );
        }
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
