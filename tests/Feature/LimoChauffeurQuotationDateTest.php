<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\QuotationForm;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoQuotation;
use Tests\TestCase;

/**
 * A chauffeur quotation keeps its start date, hours and days.
 *
 * In the browser, switching a leg from Transfer to Chauffeur reused the To box
 * as the start date box, whose script then started before its inputs existed:
 * a date picked from the calendar reached the server but the box stayed blank.
 * Each branch's boxes now carry their own wire:key (checked in headless Chrome
 * on 2026-10-22; there is no JS test runner here, so this pins the keys and the
 * server side).
 */
final class LimoChauffeurQuotationDateTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    public function test_a_chauffeur_quotation_saves_and_reopens_its_date_hours_and_days(): void
    {
        $customer = LimoCustomer::query()->create(['name' => 'Hamad']);

        Livewire::test(QuotationForm::class)
            ->set('customer_id', $customer->id)
            ->set('requested_by', 'Office')
            ->set('legs.0.service_type', 'chauffeur')
            ->set('legs.0.from_location', 'Four Seasons')
            ->set('legs.0.start_at', '2026-11-05T09:30')
            ->set('legs.0.hours', '8')
            ->set('legs.0.days', '2')
            ->set('legs.0.rate', '20')
            ->call('save')
            ->assertHasNoErrors();

        $leg = LimoLeg::query()->sole();
        $this->assertSame('2026-11-05 09:30', $leg->start_at?->format('Y-m-d H:i'));
        $this->assertSame(8.0, (float) $leg->hours);
        $this->assertSame(2, (int) $leg->days);

        Livewire::test(QuotationForm::class, ['id' => LimoQuotation::query()->sole()->id])
            ->assertSet('legs.0.start_at', '2026-11-05T09:30')
            ->assertSet('legs.0.hours', '8')
            ->assertSet('legs.0.days', '2');
    }

    public function test_switching_to_chauffeur_draws_a_fresh_start_date_box(): void
    {
        $form = Livewire::test(QuotationForm::class);
        $this->assertStringContainsString('wire:key="leg-0-transfer-start"', $form->html());
        $this->assertStringContainsString('wire:key="leg-0-transfer-to"', $form->html());

        $html = $form->set('legs.0.service_type', 'chauffeur')->html();
        $this->assertStringContainsString('wire:key="leg-0-chauffeur-start"', $html);
        $this->assertStringContainsString('wire:key="leg-0-chauffeur-hours"', $html);
        $this->assertStringContainsString('wire:key="leg-0-chauffeur-days"', $html);
        $this->assertStringNotContainsString('wire:key="leg-0-transfer-start"', $html);
    }
}
