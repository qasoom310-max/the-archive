<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Money\Currencies;
use App\Erp\Money\Currency;
use App\Erp\Settings\Setting;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * Currency registry contract — pin the per-currency decimal count, the
 * symbol-before/after rule, and the Settings handshake. Other tests rely
 * on these defaults transitively (POS receipts, list-view footers), so
 * a regression here would ripple silently.
 */
final class CurrencyFormatTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        // Settings cache is process-wide; if a previous test left a stale
        // value behind the registry would pick it up. Same for the
        // registry's own catalog memo — flush both so each case starts
        // from a clean Settings → currency.default lookup.
        Setting::flush();
        Currencies::flushCache();
    }

    public function test_catalogue_contains_the_core_arab_currencies_and_majors(): void
    {
        $all = Currencies::all();

        foreach (['BHD', 'KWD', 'OMR', 'SAR', 'AED', 'QAR', 'JOD', 'EGP', 'USD', 'EUR', 'GBP'] as $code) {
            $this->assertArrayHasKey($code, $all, "Registry must carry {$code}");
        }
    }

    public function test_bahraini_dinar_is_three_decimals_suffix_symbol(): void
    {
        $bhd = Currencies::find('BHD');
        $this->assertInstanceOf(Currency::class, $bhd);
        $this->assertSame(3, $bhd->decimals);
        $this->assertSame(Currency::POSITION_AFTER, $bhd->position);

        // Padding to 3 decimals, with the BD suffix on the right.
        $this->assertSame('20.000 BD', $bhd->format(20));
        $this->assertSame('1,234.500 BD', $bhd->format(1234.5));
    }

    public function test_us_dollar_is_two_decimals_prefix_symbol(): void
    {
        $usd = Currencies::find('USD');
        $this->assertInstanceOf(Currency::class, $usd);
        $this->assertSame(2, $usd->decimals);
        $this->assertSame(Currency::POSITION_BEFORE, $usd->position);
        $this->assertSame('$20.00', $usd->format(20));
    }

    public function test_zero_decimal_currencies_drop_the_fraction(): void
    {
        // DJF/KMF are exponent-0 in ISO 4217 — no fractional unit exists.
        $this->assertSame('1,500 Fdj', Currencies::format(1500, 'DJF'));
        $this->assertSame('1,500 CF', Currencies::format(1500, 'KMF'));
    }

    public function test_format_follows_the_setting_when_no_explicit_code(): void
    {
        Setting::set('currency.default', 'BHD');
        Currencies::flushCache();

        $this->assertSame('10.000 BD', Currencies::format(10));
    }

    public function test_format_falls_back_to_usd_when_setting_is_unknown(): void
    {
        Setting::set('currency.default', 'ZZZ-not-a-currency');
        Currencies::flushCache();

        // Unknown code → silent fallback to USD; no exception thrown so
        // a misconfigured settings row never breaks a checkout screen.
        $this->assertSame('$10.00', Currencies::format(10));
    }

    public function test_explicit_code_overrides_the_active_setting(): void
    {
        Setting::set('currency.default', 'USD');
        Currencies::flushCache();

        // Pass-through override — the WhatsApp receipt or a per-document
        // currency could pin a specific code regardless of the global.
        $this->assertSame('20.000 BD', Currencies::format(20, 'BHD'));
    }

    public function test_null_amount_renders_as_zero_in_the_active_currency(): void
    {
        Setting::set('currency.default', 'BHD');
        Currencies::flushCache();

        $this->assertSame('0.000 BD', Currencies::format(null));
    }

    public function test_view_arch_whitelist_accepts_money_format(): void
    {
        // Regression guard: the arch parser used to whitelist only
        // text/number/date/datetime/badge/bool — declaring `format: money`
        // got silently downgraded to `text`, so money columns rendered as
        // raw scalars instead of routing through the currency formatter.
        // This pins the whitelist so a future tidy-up can't undo it.
        $arch = \App\Erp\Views\ViewArch::fromArray([
            'columns' => [
                ['field' => 'price', 'label' => 'Sale Price', 'format' => 'money'],
                ['field' => 'name',  'label' => 'Name'],
            ],
        ]);

        $price = $arch->columns[0] ?? null;
        $this->assertNotNull($price);
        $this->assertSame('money', $price->format);
    }

    public function test_settings_page_offers_the_registry_as_a_dropdown(): void
    {
        $admin = \App\Models\User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $component = \Livewire\Livewire::test(\App\Livewire\Pages\SettingsPage::class);

        // The currency.default key must surface in $selects with at least
        // BHD + USD + EUR so the view falls through the `<select>` branch.
        $selects = $component->get('selects');
        $this->assertArrayHasKey('currency.default', $selects);

        $codes = array_column($selects['currency.default'], 'value');
        foreach (['BHD', 'USD', 'EUR', 'SAR'] as $expected) {
            $this->assertContains($expected, $codes, "{$expected} should appear in the picker");
        }
    }
}
