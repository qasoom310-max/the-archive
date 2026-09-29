<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Settings\Setting;
use App\Erp\Settings\SettingManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * How to pay, printed under the totals of the limousine and rental invoices.
 * It reads THIS database's settings, so each business prints its own account.
 */
final class BankDetailsTest extends TestCase
{
    use DatabaseMigrations;

    private const INVOICES = [
        'Modules/Limousine/resources/views/partials/invoice-body.blade.php',
        'Modules/Limousine/resources/views/combined-invoice-pdf.blade.php',
        'Modules/Rental/resources/views/partials/invoice-body.blade.php',
    ];

    /** @param array<string, string> $values */
    private function configure(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::set($key, $value);
        }
        app(SettingManager::class)->flush();
    }

    private function wanaan(): void
    {
        $this->configure([
            'company.name' => 'Wanaan Car Rental W.L.L',
            'company.bank_payee' => 'WANAAN CAR RENTAL W.L.L',
            'company.bank_name' => 'Al Salam Bank',
            'company.bank_account' => '765765150000',
            'company.bank_iban' => 'BH47ALSA00765765150000',
            'company.bank_swift' => 'ALSABHBM',
        ]);
    }

    public function test_it_prints_the_payee_and_the_bank_account(): void
    {
        $this->wanaan();

        $html = Blade::render('<x-bank-details />');

        $this->assertStringContainsString('Please make all cheques payable to', $html);
        $this->assertStringContainsString('WANAAN CAR RENTAL W.L.L', $html);
        $this->assertStringContainsString('Al Salam Bank', $html);
        $this->assertStringContainsString('765765150000', $html);
        $this->assertStringContainsString('BH47ALSA00765765150000', $html);
        $this->assertStringContainsString('ALSABHBM', $html);
    }

    public function test_a_database_with_no_bank_details_prints_nothing(): void
    {
        // Otherwise every other business tells its customers to pay nobody.
        $this->configure(['company.name' => 'Hashtag Limo']);

        $this->assertStringNotContainsString('data-bank-details', Blade::render('<x-bank-details />'));
    }

    public function test_a_blank_payee_falls_back_to_the_company_name(): void
    {
        $this->configure(['company.name' => 'Hashtag Limo', 'company.bank_iban' => 'BH00TEST']);

        $html = Blade::render('<x-bank-details />');

        $this->assertStringContainsString('Hashtag Limo', $html);
        $this->assertStringContainsString('BH00TEST', $html);
    }

    public function test_every_invoice_carries_the_block(): void
    {
        foreach (self::INVOICES as $relative) {
            $this->assertStringContainsString('<x-bank-details />', (string) file_get_contents(base_path($relative)), $relative);
        }
    }

    public function test_the_migration_fills_in_wanaans_account_without_overwriting(): void
    {
        $migration = require base_path('database/migrations/2026_09_29_100002_add_company_bank_settings.php');

        DB::table('ir_config_parameter')->where('key', 'like', 'company.bank_%')->delete();
        $this->configure(['company.name' => 'Wanaan Car Rental W.L.L']);
        DB::table('ir_config_parameter')->insert(['key' => 'company.bank_swift', 'value' => 'TYPEDBYHAND', 'type' => 'string', 'group' => 'General', 'label' => 'SWIFT code', 'sort' => 32]);

        $migration->up();
        app(SettingManager::class)->flush();

        $this->assertSame('Al Salam Bank', Setting::get('company.bank_name'));
        $this->assertSame('BH47ALSA00765765150000', Setting::get('company.bank_iban'));
        $this->assertSame('TYPEDBYHAND', Setting::get('company.bank_swift'));
    }

    public function test_the_migration_leaves_another_business_blank(): void
    {
        $migration = require base_path('database/migrations/2026_09_29_100002_add_company_bank_settings.php');

        DB::table('ir_config_parameter')->where('key', 'like', 'company.bank_%')->delete();
        $this->configure(['company.name' => 'Hashtag Limo', 'company.vat_number' => '']);

        $migration->up();
        app(SettingManager::class)->flush();

        $this->assertSame('', (string) Setting::get('company.bank_iban'));
        $this->assertTrue(DB::table('ir_config_parameter')->where('key', 'company.bank_iban')->exists());
    }
}
