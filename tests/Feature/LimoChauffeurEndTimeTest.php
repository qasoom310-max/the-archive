<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\BookingForm;
use Tests\TestCase;

/**
 * A chauffeur trip shows when each day ends (start time + hours per day)
 * under the Hours per day box.
 */
final class LimoChauffeurEndTimeTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    private function chauffeur(string $start, string $hours, string $days = '1'): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(BookingForm::class)
            ->set('legs.0.service_type', 'chauffeur')
            ->set('legs.0.start_at', $start)
            ->set('legs.0.hours', $hours)
            ->set('legs.0.days', $days);
    }

    public function test_start_plus_hours_shows_under_the_hours_box(): void
    {
        $this->chauffeur('2026-11-20T09:15', '4')->assertSeeHtml('data-chauffeur-end')->assertSee('Ends at 1:15 PM');
        $this->chauffeur('2026-11-20T09:15', '4.5')->assertSee('Ends at 1:45 PM');
    }

    public function test_a_several_day_job_says_each_day(): void
    {
        $this->chauffeur('2026-11-20T09:15', '8', '3')->assertSee('Each day ends at 5:15 PM');
    }

    public function test_past_midnight_says_next_day(): void
    {
        $this->chauffeur('2026-11-20T22:00', '4')->assertSee('Ends at 2:00 AM (next day)');
    }

    public function test_nothing_shows_without_a_start_or_hours(): void
    {
        $this->chauffeur('', '4')->assertDontSeeHtml('data-chauffeur-end');
        $this->chauffeur('2026-11-20T09:15', '')->assertDontSeeHtml('data-chauffeur-end');
    }
}
