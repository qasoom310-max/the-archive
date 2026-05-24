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
use Modules\Pos\Models\PosCategory;
use Tests\TestCase;

/**
 * Translation behaviour for `PosCategory.name` — mirrors the contract
 * pinned by `PosProductTranslationTest` so both translatable POS models
 * stay in lockstep (any divergence in the engine — FormView buffer,
 * arch flag parsing, fallback rules — fails here too).
 */
final class PosCategoryTranslationTest extends TestCase
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

    public function test_pos_category_stores_translations_as_json_per_locale(): void
    {
        $this->installPos();

        $category = PosCategory::query()->create(['name' => 'Drinks', 'sequence' => 1]);
        $category->setTranslation('name', 'ar', 'مشروبات')->save();

        $this->assertSame(
            ['en' => 'Drinks', 'ar' => 'مشروبات'],
            $category->fresh()->getTranslations('name'),
        );
    }

    public function test_name_reads_via_active_locale(): void
    {
        $this->installPos();

        $category = PosCategory::query()->create(['name' => 'Food', 'sequence' => 1]);
        $category->setTranslation('name', 'ar', 'طعام')->save();
        $fresh = $category->fresh();

        App::setLocale('en');
        $this->assertSame('Food', $fresh->name);

        App::setLocale('ar');
        $this->assertSame('طعام', $fresh->name);
    }

    public function test_plain_string_name_round_trips_as_english_translation(): void
    {
        // Existing seeders / imports pass a plain string for name —
        // Spatie's trait must encode it as `{"en": value}` so subsequent
        // locale reads aren't surprised by a bare scalar in the JSON column.
        $this->installPos();

        $category = PosCategory::query()->create(['name' => 'Snacks', 'sequence' => 1]);

        $this->assertSame(['en' => 'Snacks'], $category->fresh()->getTranslations('name'));
    }

    public function test_form_arch_marks_name_as_translatable(): void
    {
        $this->installPos();

        $arch = app(ViewResolver::class)->arch('pos.category', 'form');

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

    public function test_form_view_mount_hydrates_translation_buffer(): void
    {
        $this->installPos();
        $this->adminLogin();

        $category = PosCategory::query()->create(['name' => 'Hot Drinks', 'sequence' => 1]);
        $category->setTranslation('name', 'ar', 'مشروبات ساخنة')->save();

        App::setLocale('en');

        Livewire::test(FormView::class, [
            'model' => PosCategory::class,
            'modelKey' => 'pos.category',
            'recordId' => $category->id,
        ])
            ->assertSet('translations.name', ['en' => 'Hot Drinks', 'ar' => 'مشروبات ساخنة'])
            ->assertSet('translationLocale.name', 'en')
            ->assertSet('form.name', 'Hot Drinks');
    }

    public function test_switch_locale_buffers_current_edit_then_loads_target_locale_value(): void
    {
        $this->installPos();
        $this->adminLogin();

        $category = PosCategory::query()->create(['name' => 'Cold Drinks', 'sequence' => 1]);
        $category->setTranslation('name', 'ar', 'مشروبات باردة')->save();

        App::setLocale('en');

        Livewire::test(FormView::class, [
            'model' => PosCategory::class,
            'modelKey' => 'pos.category',
            'recordId' => $category->id,
        ])
            ->set('form.name', 'Cold Drinks (edited)')
            ->call('switchLocale', 'name', 'ar')
            ->assertSet('translations.name.en', 'Cold Drinks (edited)')
            ->assertSet('form.name', 'مشروبات باردة')
            ->assertSet('translationLocale.name', 'ar');
    }

    public function test_save_flushes_all_locale_values_via_set_translations(): void
    {
        $this->installPos();
        $this->adminLogin();

        $category = PosCategory::query()->create(['name' => 'Desserts', 'sequence' => 1]);

        App::setLocale('en');

        Livewire::test(FormView::class, [
            'model' => PosCategory::class,
            'modelKey' => 'pos.category',
            'recordId' => $category->id,
        ])
            ->set('form.name', 'Desserts+')
            ->call('switchLocale', 'name', 'ar')
            ->set('form.name', 'حلويات')
            ->call('save');

        $this->assertSame(
            ['en' => 'Desserts+', 'ar' => 'حلويات'],
            $category->fresh()->getTranslations('name'),
        );
    }

    public function test_arabic_only_category_falls_back_under_english_active_locale(): void
    {
        // Fallback chain: `fallbackAny=true` (set in AppServiceProvider) makes
        // Spatie return the single available translation rather than an empty
        // string. So a category created with only an Arabic name still shows
        // its Arabic value to an English-active user — the UI never blanks.
        $this->installPos();

        $category = PosCategory::query()->create([
            'name' => ['ar' => 'مأكولات بحرية'],
            'sequence' => 1,
        ]);
        $category = $category->fresh();

        App::setLocale('en');
        $this->assertSame('مأكولات بحرية', $category->name);

        App::setLocale('ar');
        $this->assertSame('مأكولات بحرية', $category->name);
    }

    public function test_changing_company_language_flips_displayed_category_name(): void
    {
        // End-to-end: same category renders in English or Arabic purely
        // from `company.language`, no per-call-site change.
        $this->installPos();
        $this->seed(SettingSeeder::class);

        $category = PosCategory::query()->create(['name' => 'Bakery', 'sequence' => 1]);
        $category->setTranslation('name', 'ar', 'مخبوزات')->save();
        $category = $category->fresh();

        Setting::set('company.language', 'en');
        App::setLocale('en');
        $this->assertSame('Bakery', $category->name);

        Setting::set('company.language', 'ar');
        App::setLocale('ar');
        $this->assertSame('مخبوزات', $category->name);
    }

    public function test_switching_to_an_unknown_locale_is_ignored(): void
    {
        $this->installPos();
        $this->adminLogin();

        $category = PosCategory::query()->create(['name' => 'Grocery', 'sequence' => 1]);

        App::setLocale('en');

        Livewire::test(FormView::class, [
            'model' => PosCategory::class,
            'modelKey' => 'pos.category',
            'recordId' => $category->id,
        ])
            ->call('switchLocale', 'name', 'fr')
            ->assertSet('translationLocale.name', 'en');
    }
}
