<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Limousine\Livewire\Receipts;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoReceipt;
use Tests\TestCase;

/**
 * The Receipts screen filters by the receipt's OWN date and by who created
 * it, on top of the existing tab/method/search filters — all from the same
 * {@see \Modules\Limousine\Services\LimoReceiptRows} source the exports read
 * (see LimoBespokeExportTest for the export-side coverage of these two).
 */
final class LimoReceiptFiltersTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('limousine');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function receipt(string $date, string $preparedBy, float $amount = 50): LimoReceipt
    {
        $customer = LimoCustomer::query()->create(['name' => 'Customer ' . $preparedBy]);
        $booking = LimoBooking::query()->create(['customer_id' => $customer->id, 'fare' => $amount]);
        $invoice = $booking->syncInvoice();

        return LimoReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'date' => $date,
            'amount' => $amount,
            'method' => 'cash',
            'prepared_by' => $preparedBy,
        ]);
    }

    public function test_the_date_range_filter_narrows_the_list(): void
    {
        $this->receipt('2026-06-01', 'Ahmed');
        $this->receipt('2026-06-20', 'Ahmed');

        Livewire::test(Receipts::class)
            ->set('tab', 'all')
            ->set('from', '2026-06-15')
            ->set('to', '2026-06-25')
            ->assertViewHas('receipts', fn ($receipts): bool => $receipts->total() === 1);
    }

    public function test_the_created_by_filter_narrows_the_list_and_offers_every_preparer(): void
    {
        $this->receipt('2026-06-01', 'Ahmed');
        $this->receipt('2026-06-02', 'Mona');

        Livewire::test(Receipts::class)
            ->set('tab', 'all')
            ->assertViewHas('preparedByOptions', ['Ahmed', 'Mona'])
            ->set('preparedBy', 'Mona')
            ->assertViewHas(
                'receipts',
                fn ($receipts): bool => $receipts->total() === 1 && $receipts->first()->prepared_by === 'Mona',
            );
    }

    public function test_the_created_by_column_is_shown_on_the_list(): void
    {
        $this->receipt('2026-06-01', 'Ahmed');

        Livewire::test(Receipts::class)
            ->set('tab', 'all')
            ->assertSee(__('Created by'))
            ->assertSee('Ahmed');
    }

    public function test_the_clear_button_appears_once_a_new_filter_is_set_and_resetting_shows_everyone_again(): void
    {
        $this->receipt('2026-06-01', 'Ahmed');
        $this->receipt('2026-06-20', 'Mona');

        Livewire::test(Receipts::class)
            ->set('tab', 'all')
            ->set('from', '2026-06-15')
            ->set('to', '2026-06-25')
            ->set('preparedBy', 'Mona')
            ->assertSee(__('Clear'))
            ->set('from', '')
            ->set('to', '')
            ->set('preparedBy', '')
            ->assertViewHas('receipts', fn ($receipts): bool => $receipts->total() === 2);
    }
}
