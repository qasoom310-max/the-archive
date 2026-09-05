<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\QuotationForm;
use Tests\TestCase;

/**
 * A half-typed date on the quotation form is not a crash.
 *
 * The validity label and the Week/Month/Year detection both read the typed
 * dates on every render, before validation has run — the same shape of bug
 * that once took down the chauffeur schedule.
 */
final class LimoQuotationDateGuardTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true, 'name' => 'Qassim']));
        app(ModuleManager::class)->install('limousine');
    }

    public function test_a_half_typed_validity_date_does_not_take_the_page_down(): void
    {
        Livewire::test(QuotationForm::class)
            ->set('valid_until', '31/')
            ->assertOk()
            ->set('quote_date', 'soon')
            ->assertOk()
            ->set('valid_until', 'never')
            ->assertOk();
    }

    public function test_a_real_date_still_shows_its_label(): void
    {
        Livewire::test(QuotationForm::class)
            ->set('valid_until', '2026-10-01')
            ->assertOk()
            ->assertViewHas('validUntilLabel', '01-Oct-2026');
    }
}
