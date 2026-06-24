<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Hr\PayrollCalculator;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Payroll overview — every active employee's computed net for a month, who's
 * been paid, and the month's payroll total (which flows into Profit &
 * Expenses). Admin-only.
 */
#[Layout('components.layouts.app')]
#[Title('Payroll')]
final class Payroll extends Component
{
    #[Url(except: '')]
    public string $month = '';

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);

        if ($this->month === '') {
            $this->month = Carbon::now()->format('Y-m');
        }
    }

    public function render(): View
    {
        $month = $this->month !== '' ? $this->month : Carbon::now()->format('Y-m');
        $calculator = app(PayrollCalculator::class);

        $rows = Employee::query()
            ->where('active', true)
            ->orderBy('sequence')->orderBy('name')
            ->get()
            ->map(function (Employee $employee) use ($calculator, $month): array {
                $payslip = $employee->payslipForPeriod($month);
                $computed = $calculator->compute($employee, $month);

                return [
                    'employee' => $employee,
                    'net' => $payslip !== null ? (float) $payslip->net : $computed['net'],
                    'paid' => $payslip !== null,
                ];
            });

        return view('livewire.pages.payroll', [
            'rows' => $rows,
            'monthLabel' => Carbon::parse($month . '-01')->isoFormat('MMMM YYYY'),
            'total' => round((float) $rows->sum('net'), 3),
            'paidTotal' => round((float) $rows->where('paid', true)->sum('net'), 3),
        ]);
    }
}
