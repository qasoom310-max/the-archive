<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Modules\Limousine\Http\Controllers\LimoExpenseImportController;
use Modules\Limousine\Http\Controllers\LimoLocationImportController;
use Modules\Limousine\Http\Controllers\LimoPettyCashImportController;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoExpense;
use Modules\Limousine\Models\LimoLocation;
use Modules\Limousine\Models\LimoPettyAdvance;
use Modules\Limousine\Support\ExpenseImporter;
use Modules\Limousine\Support\LocationImporter;
use Modules\Limousine\Support\PettyCashImporter;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Importing an expenses, locations, or petty-cash CSV (an export from a
 * previous system, the same shape each screen's own export already prints).
 * A petty-cash row lands directly at its recorded status/totals — never
 * through PettyCash::settle(), which also writes one LimoExpense row per
 * paper receipt line the CSV has no detail for.
 */
final class ExpenseLocationPettyCashImportTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function csv(string $header, string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'elp') . '.csv';
        file_put_contents($path, $header . "\n" . $body);

        return $path;
    }

    // --- Expenses ----------------------------------------------------------

    public function test_expense_imports_with_category_and_payee(): void
    {
        app(ModuleManager::class)->install('limousine');
        $path = $this->csv('Reference,Date,Category,Paid to,Amount', "EXP/00099,2026-01-10,Fuel,Ahmed,15.5\n");

        $result = app(ExpenseImporter::class)->import($path);
        $this->assertSame(1, $result['imported']);

        $expense = LimoExpense::query()->firstOrFail();
        $this->assertSame('fuel', $expense->category);
        $this->assertSame('Ahmed', $expense->payee);
        $this->assertEqualsWithDelta(15.5, $expense->amount, 0.001);
    }

    public function test_expense_unknown_category_falls_back_to_other(): void
    {
        app(ModuleManager::class)->install('limousine');
        app(ExpenseImporter::class)->import($this->csv('Reference,Date,Category,Paid to,Amount', "EXP/1,,Something Weird,,10\n"));

        $this->assertSame('other', LimoExpense::query()->firstOrFail()->category);
    }

    public function test_expense_re_import_does_not_duplicate(): void
    {
        app(ModuleManager::class)->install('limousine');
        app(ExpenseImporter::class)->import($this->csv('Reference,Date,Category,Paid to,Amount', "E/1,2026-01-10,Fuel,Ahmed,15\n"));
        $second = app(ExpenseImporter::class)->import($this->csv('Reference,Date,Category,Paid to,Amount', "E/1,2026-01-10,Fuel,Ahmed,15\n"));

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, LimoExpense::query()->count());
    }

    public function test_expense_import_is_manager_gated(): void
    {
        app(ModuleManager::class)->install('limousine');
        $this->actingAs(User::factory()->create());
        $controller = new LimoExpenseImportController();

        $file = new UploadedFile($this->csv('Reference,Date,Category,Paid to,Amount', "X,,,X,10\n"), 'e.csv', 'text/csv', null, true);
        $request = Request::create('/x', 'POST', [], [], ['file' => $file]);

        try {
            $controller($request, app(ExpenseImporter::class));
            $this->fail('A non-manager should be forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, LimoExpense::query()->count());
    }

    // --- Locations -----------------------------------------------------

    public function test_location_imports_with_area_and_active_flag(): void
    {
        app(ModuleManager::class)->install('limousine');
        $path = $this->csv('Name,Area,Notes,Active', "Bahrain Airport,Muharraq,Terminal 1,1\n");

        $result = app(LocationImporter::class)->import($path);
        $this->assertSame(1, $result['imported']);

        $location = LimoLocation::query()->firstOrFail();
        $this->assertSame('Bahrain Airport', $location->name);
        $this->assertSame('Muharraq', $location->area);
        $this->assertTrue($location->active);
    }

    public function test_location_with_same_name_is_skipped(): void
    {
        app(ModuleManager::class)->install('limousine');
        LimoLocation::query()->create(['name' => 'Bahrain Airport']);
        $result = app(LocationImporter::class)->import($this->csv('Name,Area,Notes,Active', "Bahrain Airport,,,\n"));

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, LimoLocation::query()->count());
    }

    public function test_location_import_is_manager_gated(): void
    {
        app(ModuleManager::class)->install('limousine');
        $this->actingAs(User::factory()->create());
        $controller = new LimoLocationImportController();

        $file = new UploadedFile($this->csv('Name,Area,Notes,Active', "X,,,\n"), 'l.csv', 'text/csv', null, true);
        $request = Request::create('/x', 'POST', [], [], ['file' => $file]);

        try {
            $controller($request, app(LocationImporter::class));
            $this->fail('A non-manager should be forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, LimoLocation::query()->count());
    }

    // --- Petty cash ------------------------------------------------------

    public function test_petty_cash_imports_and_lands_cleared_with_computed_shortfall(): void
    {
        app(ModuleManager::class)->install('limousine');
        $path = $this->csv('Reference,Driver,Date,Given,Receipts,Status', "PC/00099,Hassan,2026-01-10,50,40,\n");

        $result = app(PettyCashImporter::class)->import($path);
        $this->assertSame(1, $result['imported']);

        $advance = LimoPettyAdvance::query()->firstOrFail();
        $this->assertSame('Hassan', $advance->driver->name);
        $this->assertSame(LimoPettyAdvance::STATUS_CLEARED, $advance->status);
        $this->assertEqualsWithDelta(50.0, $advance->amount, 0.001);
        $this->assertEqualsWithDelta(40.0, $advance->receipts_total, 0.001);
        $this->assertEqualsWithDelta(10.0, $advance->shortfall, 0.001);
        $this->assertEqualsWithDelta(0.0, $advance->excess, 0.001);
        $this->assertNotNull($advance->settled_at);
        // No line-level detail in the CSV, so no lines and no expense-ledger
        // rows are synthesised the way a live settle() would create.
        $this->assertSame(0, $advance->lines()->count());
        $this->assertSame(0, LimoExpense::query()->count());
    }

    public function test_petty_cash_reuses_an_existing_driver(): void
    {
        app(ModuleManager::class)->install('limousine');
        $existing = LimoDriver::query()->create(['name' => 'Hassan']);
        app(PettyCashImporter::class)->import($this->csv('Reference,Driver,Date,Given,Receipts,Status', "PC/1,Hassan,,50,,\n"));

        $this->assertSame(1, LimoDriver::query()->count());
        $this->assertSame($existing->id, LimoPettyAdvance::query()->firstOrFail()->driver_id);
    }

    public function test_petty_cash_still_issued_status_is_left_unsettled(): void
    {
        app(ModuleManager::class)->install('limousine');
        app(PettyCashImporter::class)->import($this->csv('Reference,Driver,Date,Given,Receipts,Status', "PC/1,Hassan,2026-01-10,50,,Issued\n"));

        $advance = LimoPettyAdvance::query()->firstOrFail();
        $this->assertSame(LimoPettyAdvance::STATUS_ISSUED, $advance->status);
        $this->assertNull($advance->settled_at);
        $this->assertEqualsWithDelta(0.0, $advance->receipts_total, 0.001);
    }

    public function test_petty_cash_re_import_does_not_duplicate(): void
    {
        app(ModuleManager::class)->install('limousine');
        app(PettyCashImporter::class)->import($this->csv('Reference,Driver,Date,Given,Receipts,Status', "P/1,Hassan,2026-01-10,50,,\n"));
        $second = app(PettyCashImporter::class)->import($this->csv('Reference,Driver,Date,Given,Receipts,Status', "P/1,Hassan,2026-01-10,50,,\n"));

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, LimoPettyAdvance::query()->count());
    }

    public function test_petty_cash_import_is_manager_gated(): void
    {
        app(ModuleManager::class)->install('limousine');
        $this->actingAs(User::factory()->create());
        $controller = new LimoPettyCashImportController();

        $file = new UploadedFile($this->csv('Reference,Driver,Date,Given,Receipts,Status', "X,X,,50,,\n"), 'p.csv', 'text/csv', null, true);
        $request = Request::create('/x', 'POST', [], [], ['file' => $file]);

        try {
            $controller($request, app(PettyCashImporter::class));
            $this->fail('A non-manager should be forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(0, LimoPettyAdvance::query()->count());
    }
}
