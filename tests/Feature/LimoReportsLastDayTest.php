<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Reports;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoExpense;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoReceipt;
use Tests\TestCase;

/**
 * The Limousine report counts the whole of its last day. The business
 * databases are SQLite, which keeps a date as "2026-06-30 00:00:00" — text
 * that sorts after "2026-06-30" — so a range ending on the 30th used to drop
 * every receipt and expense dated the 30th.
 */
final class LimoReportsLastDayTest extends TestCase
{
    use DatabaseMigrations;

    public function test_receipts_and_expenses_of_the_last_day_count(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');

        $customer = LimoCustomer::query()->create(['name' => 'Zain']);
        $invoice = LimoInvoice::query()->create(['customer_id' => $customer->id, 'issue_date' => '2026-06-10', 'total' => 50]);
        LimoReceipt::query()->create(['invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'date' => '2026-06-01', 'amount' => 20, 'method' => 'cash']);
        LimoReceipt::query()->create(['invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'date' => '2026-06-30', 'amount' => 30, 'method' => 'cash']);
        LimoExpense::query()->create(['date' => '2026-06-30', 'category' => 'fuel', 'amount' => 12]);
        // Outside the range: not counted.
        LimoExpense::query()->create(['date' => '2026-07-01', 'category' => 'fuel', 'amount' => 99]);

        Livewire::test(Reports::class)
            ->set('from', '2026-06-01')
            ->set('to', '2026-06-30')
            ->assertViewHas('summary', static fn (array $summary): bool => $summary['collected'] === 50.0
                && $summary['expenses'] === 12.0
                && $summary['net'] === 38.0);
    }

    /**
     * The bookings tab names the route from the trips — the booking-level
     * fields are empty on everything entered here, so it read "— → —" — and a
     * held-back trip shows only its countries to someone under the lock.
     */
    public function test_the_bookings_tab_names_the_route_from_the_trips(): void
    {
        app(ModuleManager::class)->install('limousine');
        $customer = LimoCustomer::query()->create(['name' => 'Zain']);
        $booking = \Modules\Limousine\Models\LimoBooking::query()->create([
            'reference' => 'BK/07001', 'customer_id' => $customer->id, 'pickup_at' => '2026-06-12 09:00:00',
            'status' => \Modules\Limousine\Models\LimoBooking::STATUS_CONFIRMED, 'fare' => 25,
        ]);
        $leg = \Modules\Limousine\Models\LimoLeg::query()->create([
            'legable_type' => \Modules\Limousine\Models\LimoBooking::class, 'legable_id' => $booking->id, 'sequence' => 0,
            'status' => \Modules\Limousine\Models\LimoLeg::STATUS_CONFIRMED, 'start_at' => '2026-06-12 09:00:00',
            'from_location' => 'Seef Mall', 'to_location' => 'Bahrain Airport', 'vehicle_details' => 'Sedan',
            'rate' => 25, 'net_amount' => 25,
        ]);
        $leg->forceFill(['from_country' => 'BH', 'to_country' => 'BH'])->saveQuietly();

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Livewire::test(Reports::class)->set('from', '2026-06-01')->set('to', '2026-06-30')->set('tab', 'bookings')
            ->assertSee('Seef Mall')
            ->assertSee('Bahrain Airport');

        $locked = User::factory()->create(['is_admin' => true]);
        $locked->forceFill(['trip_details_locked' => true])->save();
        $this->actingAs($locked);
        Livewire::test(Reports::class)->set('from', '2026-06-01')->set('to', '2026-06-30')->set('tab', 'bookings')
            ->assertDontSee('Seef Mall')
            ->assertSee('BK/07001');
    }
}
