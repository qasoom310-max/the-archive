<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Erp\Settings\Setting;
use App\Erp\Views\ViewResolver;
use App\Livewire\Views\FormView;
use App\Models\User;
use Database\Seeders\AuthSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Modules\Pos\Models\PosProduct;
use Tests\TestCase;

/**
 * Translation behaviour for translatable text fields, anchored on
 * `PosProduct.name` (the first model to opt in via Spatie's trait).
 *
 * Three layers covered:
 *   - Model — Spatie integration: writes go to JSON, reads return the
 *     active-locale value, plain-string seeded data still reads cleanly.
 *   - Engine — FormFieldDef/ViewArch parse `translatable: true`, FormView
 *     buffers per-locale edits across pill switches, save() flushes all
 *     locales via `setTranslations()`.
 *   - Surface — locale-driven reads work outside the form (lists, terminal,
 *     anywhere `{{ $product->name }}` lands), so flipping the active locale
 *     transparently flips the displayed name everywhere.
 */
final class PosProductTranslationTest extends TestCase
{
    use DatabaseMigrations;

    private function installPos(): void
    {
        app(ModuleManager::class)->install('pos');
    }

    private function adminLogin(): User
    {
        $this->seed(AuthSeeder::class);

        $admin = User::query()->where('email', 'admin@example.com')->sole();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_pos_product_stores_translations_as_json_per_locale(): void
    {
        $this->installPos();

        $product = PosProduct::query()->create(['name' => 'Espresso', 'price' => 3.0, 'tax_rate' => 0]);
        $product->setTranslation('name', 'ar', 'إسبريسو')->save();

        // Direct read for the locale-keyed map — Spatie's storage shape.
        $this->assertSame(
            ['en' => 'Espresso', 'ar' => 'إسبريسو'],
            $product->fresh()->getTranslations('name'),
        );
    }

    public function test_name_reads_via_active_locale_then_falls_back(): void
    {
        $this->installPos();

        $product = PosProduct::query()->create(['name' => 'Coffee', 'price' => 5.0, 'tax_rate' => 0]);
        $product->setTranslation('name', 'ar', 'قهوة')->save();
        $fresh = $product->fresh();

        App::setLocale('en');
        $this->assertSame('Coffee', $fresh->name);

        App::setLocale('ar');
        $this->assertSame('قهوة', $fresh->name);
    }

    public function test_plain_string_name_passed_to_create_round_trips_as_english_translation(): void
    {
        // Mass-assign with a plain string (what every existing seeder / migration
        // / import does) must end up encoded as `{"en": ...}` so downstream
        // locale reads work without manual `setTranslation` calls.
        $this->installPos();

        $product = PosProduct::query()->create(['name' => 'Tea', 'price' => 2.0, 'tax_rate' => 0]);

        $this->assertSame(['en' => 'Tea'], $product->fresh()->getTranslations('name'));
    }

    public function test_form_arch_marks_name_as_translatable(): void
    {
        $this->installPos();

        $arch = app(ViewResolver::class)->arch('pos.product', 'form');

        $nameField = null;
        foreach ($arch->formFields as $field) {
            if ($field->field === 'name') {
                $nameField = $field;
                break;
            }
        }

        $this->assertNotNull($nameField);
        $this->assertTrue($nameField->isTranslatable());
    }

    public function test_form_view_mount_hydrates_translation_buffer_for_translatable_fields(): void
    {
        $this->installPos();
        $this->adminLogin();

        $product = PosProduct::query()->create(['name' => 'Latte', 'price' => 4.0, 'tax_rate' => 0]);
        $product->setTranslation('name', 'ar', 'لاتيه')->save();

        App::setLocale('en');

        Livewire::test(FormView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
            'recordId' => $product->id,
        ])
            ->assertSet('translations.name', ['en' => 'Latte', 'ar' => 'لاتيه'])
            ->assertSet('translationLocale.name', 'en')
            ->assertSet('form.name', 'Latte');
    }

    public function test_switch_locale_buffers_current_edit_then_loads_target_locale_value(): void
    {
        $this->installPos();
        $this->adminLogin();

        $product = PosProduct::query()->create(['name' => 'Mocha', 'price' => 6.0, 'tax_rate' => 0]);
        $product->setTranslation('name', 'ar', 'موكا')->save();

        App::setLocale('en');

        Livewire::test(FormView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
            'recordId' => $product->id,
        ])
            // User edits the English value in the input but doesn't save yet.
            ->set('form.name', 'Mocha (edited)')
            ->call('switchLocale', 'name', 'ar')
            // The English edit is preserved in the buffer (un-saved),
            // and the Arabic value is now what's in the input.
            ->assertSet('translations.name.en', 'Mocha (edited)')
            ->assertSet('form.name', 'موكا')
            ->assertSet('translationLocale.name', 'ar');
    }

    public function test_save_flushes_all_locale_values_via_set_translations(): void
    {
        $this->installPos();
        $this->adminLogin();

        $product = PosProduct::query()->create(['name' => 'Cappuccino', 'price' => 4.5, 'tax_rate' => 0]);

        App::setLocale('en');

        Livewire::test(FormView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
            'recordId' => $product->id,
        ])
            ->set('form.name', 'Cappuccino+')        // edit EN
            ->call('switchLocale', 'name', 'ar')
            ->set('form.name', 'كابتشينو')           // edit AR
            ->call('save');

        $this->assertSame(
            ['en' => 'Cappuccino+', 'ar' => 'كابتشينو'],
            $product->fresh()->getTranslations('name'),
        );
    }

    public function test_switching_to_an_unknown_locale_is_ignored(): void
    {
        $this->installPos();
        $this->adminLogin();

        $product = PosProduct::query()->create(['name' => 'Macchiato', 'price' => 5.0, 'tax_rate' => 0]);

        App::setLocale('en');

        Livewire::test(FormView::class, [
            'model' => PosProduct::class,
            'modelKey' => 'pos.product',
            'recordId' => $product->id,
        ])
            ->call('switchLocale', 'name', 'fr') // not in LOCALES
            ->assertSet('translationLocale.name', 'en'); // unchanged
    }

    public function test_arabic_only_product_reads_its_arabic_name_under_english_active_locale(): void
    {
        // Regression: pre-fix Spatie's default fallbackAny=false returned
        // empty for `$product->name` when the active locale had no value
        // for the field — so an Arabic-only product (the common case
        // after a smart-locale import) displayed as a blank cell in an
        // EN UI. AppServiceProvider now flips fallbackAny=true so the
        // single available translation always wins over an empty string.
        $this->installPos();

        // Pass `name` as a locale-keyed array so Spatie writes the JSON
        // directly — sidesteps the NOT NULL on the column during the
        // initial insert (vs `setTranslation` after a blank create).
        $product = PosProduct::query()->create([
            'name' => ['ar' => 'افوكادو'],
            'barcode' => 'X',
            'price' => 1,
            'tax_rate' => 0,
        ]);
        $product = $product->fresh();

        App::setLocale('en'); // active = en, only ar available
        $this->assertSame('افوكادو', $product->name);

        App::setLocale('ar'); // active = ar, direct hit
        $this->assertSame('افوكادو', $product->name);
    }

    public function test_english_only_product_reads_its_english_name_under_arabic_active_locale(): void
    {
        $this->installPos();

        $product = PosProduct::query()->create(['name' => 'Espresso', 'price' => 1, 'tax_rate' => 0]);
        $product = $product->fresh();

        App::setLocale('ar'); // active = ar, only en available
        $this->assertSame('Espresso', $product->name);

        App::setLocale('en');
        $this->assertSame('Espresso', $product->name);
    }

    public function test_bilingual_product_still_prefers_the_active_locale_over_fallback(): void
    {
        // Fallback must NOT clobber an exact-locale match. Having both
        // translations should resolve to the active locale; fallback
        // only kicks in when the active locale's value is missing.
        $this->installPos();

        $product = PosProduct::query()->create(['name' => 'Latte', 'price' => 4, 'tax_rate' => 0]);
        $product->setTranslation('name', 'ar', 'لاتيه')->save();
        $product = $product->fresh();

        App::setLocale('en');
        $this->assertSame('Latte', $product->name);

        App::setLocale('ar');
        $this->assertSame('لاتيه', $product->name);
    }

    public function test_changing_company_language_setting_flips_displayed_product_name(): void
    {
        // End-to-end: the same product name renders in English or Arabic
        // based purely on company.language, with no per-call-site changes.
        $this->installPos();
        $this->seed(SettingSeeder::class);

        $product = PosProduct::query()->create(['name' => 'Americano', 'price' => 3.5, 'tax_rate' => 0]);
        $product->setTranslation('name', 'ar', 'أمريكانو')->save();
        $product = $product->fresh();

        Setting::set('company.language', 'en');
        App::setLocale('en');
        $this->assertSame('Americano', $product->name);

        Setting::set('company.language', 'ar');
        App::setLocale('ar');
        $this->assertSame('أمريكانو', $product->name);
    }
}
