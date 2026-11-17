<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Modules\Limousine\Livewire\ExpenseVat;
use Modules\Limousine\Models\LimoExpense;
use Modules\Limousine\Support\ExpenseVatReport;
use Tests\TestCase;

/**
 * The quarterly VAT report on limousine expenses: BHD only, and the Bahrain
 * VAT that can be claimed is never mixed with VAT paid in another country.
 */
final class LimoExpenseVatReportTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-17 10:00:00');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        app(ModuleManager::class)->install('limousine');
        Route::middleware('web')->group(base_path('Modules/Limousine/routes/web.php'));
        Route::getRoutes()->refreshNameLookups();

        // Q4 2026: two Bahrain bills, one Saudi bill (in riyals), one with no VAT.
        LimoExpense::query()->create(['date' => '2026-10-01', 'payee' => 'Garage A', 'net_amount' => 100, 'vat_rate' => 10]);
        LimoExpense::query()->create(['date' => '2026-12-31', 'payee' => 'Parts B', 'net_amount' => 50, 'vat_rate' => 10]);
        $saudi = new LimoExpense(['date' => '2026-11-05', 'payee' => 'Station Khobar', 'net_amount' => 20, 'vat_rate' => 15]);
        $saudi->setAttribute('bill_currency', 'SAR');
        $saudi->setAttribute('bill_amount', 200);
        $saudi->setAttribute('bill_rate', 0.1);
        $saudi->save();
        LimoExpense::query()->create(['date' => '2026-11-06', 'payee' => 'Parking', 'net_amount' => 3]);

        // Outside the quarter, either side.
        LimoExpense::query()->create(['date' => '2026-09-30', 'payee' => 'Earlier', 'net_amount' => 1000, 'vat_rate' => 10]);
        LimoExpense::query()->create(['date' => '2027-01-01', 'payee' => 'Later', 'net_amount' => 1000, 'vat_rate' => 10]);
    }

    public function test_bahrain_vat_is_totalled_apart_from_foreign_vat(): void
    {
        $report = (new ExpenseVatReport(2026, 4))->report();

        $this->assertSame(['Garage A', 'Parts B'], array_map(static fn (LimoExpense $e): string => (string) $e->payee, $report['bahrain']));
        $this->assertSame(['Station Khobar'], array_map(static fn (LimoExpense $e): string => (string) $e->payee, $report['foreign']));

        $this->assertSame([
            'bahrain_net' => 150.0, 'bahrain_vat' => 15.0, 'bahrain_total' => 165.0,
            'foreign_net' => 20.0, 'foreign_vat' => 3.0, 'foreign_total' => 23.0,
            'none_count' => 1, 'none_total' => 3.0, 'all_total' => 191.0,
        ], $report['totals']);

        // Neither Bahrain bill has a photo of the bill.
        $this->assertSame(2, $report['missing']);
    }

    public function test_the_page_opens_on_the_current_quarter_and_can_move_to_another(): void
    {
        Livewire::test(ExpenseVat::class)
            ->assertSet('year', 2026)->assertSet('quarter', 4)
            ->assertSee('01/10/2026')->assertSee('31/12/2026')
            ->assertSee('Garage A')->assertSee('Station Khobar')
            ->assertDontSee('Earlier')->assertDontSee('Later')
            ->call('setQuarter', 2026, 3)
            ->assertSee('Earlier')->assertDontSee('Garage A')
            // Nonsense from the address bar falls back to today's quarter.
            ->call('setQuarter', 1999, 9)
            ->assertSet('year', 2026)->assertSet('quarter', 4);

        $this->get('/app/limousine/expense/vat-report?year=2026&quarter=4')->assertOk();
        $this->get('/app/limousine/expense')->assertOk()->assertSee('VAT report');
    }

    public function test_the_spreadsheet_holds_the_same_bills_in_bhd(): void
    {
        $csv = $this->get('/app/limousine/expense/vat-report/export?year=2026&quarter=4')->assertOk()->streamedContent();

        $this->assertStringContainsString('Bahrain,2026-10-01', $csv);
        $this->assertStringContainsString('"Foreign (not claimed)",2026-11-05', $csv);
        $this->assertStringContainsString('20.000,15,3.000,23.000,SAR,200.000,0.1,no', $csv);
        $this->assertStringContainsString('"Bahrain VAT to claim",,,,,150.000,,15.000,165.000', $csv);
        $this->assertStringNotContainsString('Earlier', $csv);
    }

    public function test_someone_without_expense_access_cannot_open_or_download_it(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ExpenseVat::class)->assertForbidden();
        $this->get('/app/limousine/expense/vat-report/export?year=2026&quarter=4')->assertForbidden();
    }
}
