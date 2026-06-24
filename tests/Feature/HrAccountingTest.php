<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Livewire\Pages\EmployeePayroll;
use App\Livewire\Pages\MonthlyProfit;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Providers\AccountingServiceProvider;
use Tests\TestCase;

/**
 * Paying salaries and expenses must post real double-entry journal entries to
 * the Accounting module: Dr Salaries|Operating Expenses / Cr Bank.
 */
final class HrAccountingTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        app(ModuleManager::class)->install('accounting');
        // Modules boot before setUp installs them, so re-register the provider
        // to merge its config + wire the listeners (the known module-boot gap).
        $this->app->register(AccountingServiceProvider::class);
        (new ChartOfAccountsSeeder())->run();
    }

    public function test_paying_a_salary_books_dr_salaries_cr_bank(): void
    {
        $month = Carbon::now()->format('Y-m');
        $employee = Employee::query()->create(['name' => 'Ali', 'basic_salary' => 300]);

        Livewire::test(EmployeePayroll::class, ['id' => $employee->id])->call('markPaid');

        $entry = JournalEntry::query()->where('reference', "SAL/{$employee->id}/{$month}")->first();
        $this->assertNotNull($entry);
        $this->assertTrue($entry->isPosted());

        $salaries = Account::byCode('5020');
        $bank = Account::byCode('1020');
        $this->assertNotNull($salaries);
        $this->assertNotNull($bank);
        $this->assertSame(300.0, (float) $entry->items()->where('account_id', $salaries->id)->sum('debit'));
        $this->assertSame(300.0, (float) $entry->items()->where('account_id', $bank->id)->sum('credit'));
    }

    public function test_unmarking_a_salary_reverses_the_entry(): void
    {
        $month = Carbon::now()->format('Y-m');
        $employee = Employee::query()->create(['name' => 'Ali', 'basic_salary' => 300]);

        $component = Livewire::test(EmployeePayroll::class, ['id' => $employee->id])->call('markPaid');
        $this->assertNotNull(JournalEntry::query()->where('reference', "SAL/{$employee->id}/{$month}")->first());

        $component->call('unmarkPaid');
        $this->assertNull(JournalEntry::query()->where('reference', "SAL/{$employee->id}/{$month}")->first());
    }

    public function test_paying_an_expense_books_dr_operating_expenses_cr_bank(): void
    {
        $month = Carbon::now()->format('Y-m');
        $rent = Expense::query()->create(['name' => 'Rent', 'category' => 'rent', 'amount' => 100]);

        Livewire::test(MonthlyProfit::class)
            ->call('openPay', $rent->id)
            ->set('payAmount', '100')
            ->set('payDate', Carbon::now()->toDateString())
            ->call('savePay')
            ->assertHasNoErrors();

        $entry = JournalEntry::query()->where('reference', "EXP/{$rent->id}/{$month}")->first();
        $this->assertNotNull($entry);

        $opex = Account::byCode('5030');
        $bank = Account::byCode('1020');
        $this->assertNotNull($opex);
        $this->assertSame(100.0, (float) $entry->items()->where('account_id', $opex->id)->sum('debit'));
        $this->assertSame(100.0, (float) $entry->items()->where('account_id', $bank?->id)->sum('credit'));
    }
}
