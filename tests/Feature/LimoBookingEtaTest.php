<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Bookings;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Tests\TestCase;

/**
 * The bookings list says when each car is expected to arrive, on the tabs
 * where a car is on its way or was: Queue, Active and Completed.
 */
final class LimoBookingEtaTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
    }

    /** @param array<string, mixed> $leg */
    private function trip(array $leg, string $status = LimoLeg::STATUS_QUEUE): LimoLeg
    {
        $booking = LimoBooking::query()->create([
            'customer_id' => LimoCustomer::query()->create(['name' => 'Helen Friberg'])->id,
            'status' => LimoBooking::STATUS_QUEUE,
        ]);

        return LimoLeg::query()->create($leg + [
            'legable_type' => LimoBooking::class,
            'legable_id' => $booking->id,
            'sequence' => 0,
            'status' => $status,
            'from_location' => 'Seef',
            'to_location' => 'Airport',
            'rate' => 20, 'net_amount' => 20,
        ]);
    }

    public function test_a_transfer_arrives_its_estimate_after_it_leaves(): void
    {
        $leg = $this->trip(['start_at' => '2026-10-24 18:30:00', 'estimated_hours' => 2]);

        $this->assertSame('2026-10-24 20:30', $leg->eta()?->format('Y-m-d H:i'));
    }

    public function test_no_estimate_means_no_eta(): void
    {
        $this->assertNull($this->trip(['start_at' => '2026-10-24 18:30:00'])->eta());
        $this->assertNull($this->trip(['estimated_hours' => 2])->eta());
    }

    public function test_a_chauffeur_job_ends_when_its_last_days_hours_run_out(): void
    {
        $leg = $this->trip([
            'service_type' => LimoLeg::TYPE_CHAUFFEUR, 'start_at' => '2026-10-24 09:00:00',
            'hours' => 8.5, 'days' => 3, 'to_location' => null,
        ]);

        $this->assertSame('2026-10-26 17:30', $leg->eta()?->format('Y-m-d H:i'));
    }

    /** @return list<array{string}> */
    public static function tabsWithEta(): array
    {
        return [[LimoLeg::STATUS_QUEUE], [LimoLeg::STATUS_ACTIVE], [LimoLeg::STATUS_COMPLETED]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tabsWithEta')]
    public function test_the_tab_shows_the_eta_column(string $tab): void
    {
        $start = Carbon::now()->addDay()->setTime(18, 30);
        $this->trip(['start_at' => $start, 'estimated_hours' => 3], $tab);

        Livewire::test(Bookings::class)->call('openTab', $tab)
            ->assertSeeHtml('data-eta-heading')
            ->assertSeeHtml('data-eta>' . $start->copy()->addHours(3)->isoFormat('DD-MMM-YY HH:mm') . '</td>');
    }

    public function test_other_tabs_do_not_show_it(): void
    {
        $this->trip(['start_at' => Carbon::now()->addDay(), 'estimated_hours' => 3]);

        foreach (['all', 'unpaid', 'cancelled', 'confirmed'] as $tab) {
            Livewire::test(Bookings::class)->call('openTab', $tab)->assertDontSeeHtml('data-eta-heading');
        }
    }

    public function test_a_trip_with_no_estimate_shows_a_dash(): void
    {
        $this->trip(['start_at' => Carbon::now()->addDay()]);

        Livewire::test(Bookings::class)->call('openTab', 'queue')->assertSeeHtml('data-eta>—</td>');
    }

    public function test_the_exports_keep_their_columns(): void
    {
        $this->assertArrayNotHasKey('eta', app(\Modules\Limousine\Services\LimoQueueRows::class)->headings());
    }
}
