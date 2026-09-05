<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Reports\MonthlyFinancials;
use App\Events\ExpensePaid;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Attributes\Locked;

/**
 * Monthly Profit & Expenses — the owner's real P&L: Sales − COGS = Gross
 * Profit, minus operating expenses (the recurring bills tracked here) = Net
 * Profit. Recurring bills (Rent, EWA, SIO, LMRA…) are defined once and marked
 * paid each month with their actual amount. Admin-only.
 */
#[Layout('components.layouts.app')]
#[Title('Profit & Expenses')]
final class MonthlyProfit extends Component
{
    /** Selected month as 'YYYY-MM'. */
    #[Url(except: '')]
    public string $month = '';

    /** Add-recurring-bill form. */
    public bool $addingBill = false;

    /** @var array<string, string> */
    public array $newBill = ['name' => '', 'category' => 'other', 'amount' => '', 'due_day' => ''];

    /** Mark-paid inline editor: which bill + the actual amount/date. */
    #[Locked]
    public ?int $payingExpenseId = null;

    public string $payAmount = '';

    public string $payDate = '';

    /**
     * Payroll and company expenses are admin-only. Re-checked on EVERY action:
     * mount() runs once and Livewire then dispatches straight to methods, so a
     * mount-only gate leaves a page that is already open fully usable by someone
     * who has since been demoted.
     */
    private function guardAdmin(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->isAdmin(), 403);
    }

    public function mount(): void
    {
        $this->guardAdmin();

        if ($this->month === '') {
            $this->month = Carbon::now()->format('Y-m');
        }
    }

    public function openAddBill(): void
    {
        $this->guardAdmin();
        $this->newBill = ['name' => '', 'category' => 'other', 'amount' => '', 'due_day' => ''];
        $this->resetValidation();
        $this->addingBill = true;
    }

    public function addBill(): void
    {
        $this->guardAdmin();
        $this->validate([
            'newBill.name' => ['required', 'string', 'max:255'],
            'newBill.category' => ['required', 'string', 'max:50'],
            'newBill.amount' => ['nullable', 'numeric', 'min:0'],
            'newBill.due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
        ]);

        Expense::query()->create([
            'name' => trim($this->newBill['name']),
            'category' => $this->newBill['category'],
            'amount' => $this->newBill['amount'] === '' ? 0 : round((float) $this->newBill['amount'], 3),
            'due_day' => $this->newBill['due_day'] === '' ? null : (int) $this->newBill['due_day'],
        ]);

        $this->addingBill = false;
    }

    public function removeBill(int $expenseId): void
    {
        $this->guardAdmin();
        Expense::query()->whereKey($expenseId)->delete();
    }

    public function openPay(int $expenseId): void
    {
        $this->guardAdmin();
        $expense = Expense::query()->find($expenseId);
        if ($expense === null) {
            return;
        }

        // Prefill with this month's existing payment if any, else the template.
        $existing = $expense->paymentForPeriod($this->month);

        $this->payingExpenseId = $expenseId;
        $this->payAmount = (string) ($existing !== null ? $existing->amount : $expense->amount);
        $this->payDate = ($existing !== null ? $existing->paid_on->toDateString() : Carbon::now()->toDateString());
        $this->resetValidation();
    }

    public function closePay(): void
    {
        $this->payingExpenseId = null;
        $this->payAmount = '';
        $this->payDate = '';
    }

    public function savePay(): void
    {
        $this->guardAdmin();
        if ($this->payingExpenseId === null) {
            return;
        }

        $this->validate([
            'payAmount' => ['required', 'numeric', 'min:0'],
            'payDate' => ['required', 'date'],
        ]);

        $expense = Expense::query()->find($this->payingExpenseId);
        if ($expense === null) {
            $this->closePay();

            return;
        }

        $amount = round((float) $this->payAmount, 3);

        ExpensePayment::query()->updateOrCreate(
            ['expense_id' => $expense->id, 'period' => $this->month],
            [
                'name' => $expense->name,
                'amount' => $amount,
                'paid_on' => $this->payDate,
            ],
        );

        // Book it (Dr Operating Expenses / Cr Bank) if Accounting is installed.
        event(new ExpensePaid(
            expenseId: (int) $expense->id,
            name: (string) $expense->name,
            period: $this->month,
            amount: $amount,
            paidOn: (string) $this->payDate,
        ));

        $this->closePay();
    }

    public function unmarkPaid(int $expenseId): void
    {
        $this->guardAdmin();
        ExpensePayment::query()
            ->where('expense_id', $expenseId)
            ->where('period', $this->month)
            ->delete();
    }

    public function render(): View
    {
        $month = $this->month !== '' ? $this->month : Carbon::now()->format('Y-m');

        $bills = Expense::query()
            ->where('active', true)
            ->orderBy('sequence')
            ->orderBy('name')
            ->with(['payments' => fn ($q) => $q->where('period', $month)])
            ->get();

        return view('livewire.pages.monthly-profit', [
            'financials' => app(MonthlyFinancials::class)->forMonth($month),
            'bills' => $bills,
            'monthLabel' => Carbon::parse($month . '-01')->isoFormat('MMMM YYYY'),
            'categories' => Expense::CATEGORIES,
        ]);
    }
}
