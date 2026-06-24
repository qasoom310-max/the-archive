<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Hr\OvertimeCalculator;
use App\Erp\Hr\PayrollCalculator;
use App\Models\Employee;
use App\Models\EmployeeAbsence;
use App\Models\EmployeeOvertime;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One employee's payroll for a month: log overtime sessions (auto-split into
 * Bahrain day/night bands) and unpaid absences, see the computed salary
 * (basic + overtime − deductions), and finalise it as a paid {@see Payslip}
 * that flows into the Profit & Expenses P&L. Admin-only.
 */
#[Layout('components.layouts.app')]
#[Title('Employee payroll')]
final class EmployeePayroll extends Component
{
    public int $id;

    #[Url(except: '')]
    public string $month = '';

    // Add-overtime form.
    public string $otDate = '';

    public string $otStart = '';

    public string $otEnd = '';

    // Add-absence form.
    public string $absDate = '';

    public string $absType = 'absent';

    public function mount(int $id): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);

        $this->id = $id;
        Employee::query()->findOrFail($id);

        if ($this->month === '') {
            $this->month = Carbon::now()->format('Y-m');
        }
        $this->otDate = Carbon::now()->toDateString();
        $this->absDate = Carbon::now()->toDateString();
    }

    public function addOvertime(): void
    {
        $this->validate([
            'otDate' => ['required', 'date'],
            'otStart' => ['required', 'date_format:H:i'],
            'otEnd' => ['required', 'date_format:H:i'],
        ]);

        EmployeeOvertime::query()->create([
            'employee_id' => $this->id,
            'work_date' => $this->otDate,
            'start_time' => $this->otStart,
            'end_time' => $this->otEnd,
        ]);

        $this->otStart = '';
        $this->otEnd = '';
    }

    public function removeOvertime(int $overtimeId): void
    {
        EmployeeOvertime::query()->where('id', $overtimeId)->where('employee_id', $this->id)->delete();
    }

    public function addAbsence(): void
    {
        $this->validate([
            'absDate' => ['required', 'date'],
            'absType' => ['required', 'in:absent,sick'],
        ]);

        EmployeeAbsence::query()->create([
            'employee_id' => $this->id,
            'date' => $this->absDate,
            'type' => $this->absType,
            'paid' => $this->absType === 'sick',  // sick = paid; absent = unpaid (deducts)
        ]);
    }

    public function removeAbsence(int $absenceId): void
    {
        EmployeeAbsence::query()->where('id', $absenceId)->where('employee_id', $this->id)->delete();
    }

    public function markPaid(): void
    {
        $employee = Employee::query()->find($this->id);
        if ($employee === null) {
            return;
        }

        $c = app(PayrollCalculator::class)->compute($employee, $this->month);

        Payslip::query()->updateOrCreate(
            ['employee_id' => $employee->id, 'period' => $this->month],
            [
                'basic' => $c['basic'],
                'overtime_pay' => $c['overtime_pay'],
                'absence_deduction' => $c['absence_deduction'],
                'other_deductions' => 0,
                'allowances' => 0,
                'net' => $c['net'],
                'paid_on' => Carbon::now()->toDateString(),
            ],
        );
    }

    public function unmarkPaid(): void
    {
        Payslip::query()->where('employee_id', $this->id)->where('period', $this->month)->delete();
    }

    public function render(): View
    {
        $employee = Employee::query()->findOrFail($this->id);
        [$start, $end] = $this->monthWindow();

        $overtimeCalc = app(OvertimeCalculator::class);
        $overtimes = $employee->overtimes()
            ->whereBetween('work_date', [$start, $end])
            ->orderBy('work_date')
            ->get()
            ->map(function (EmployeeOvertime $o) use ($overtimeCalc): array {
                [$from, $to] = $o->interval();
                $split = $overtimeCalc->split($from, $to);

                return ['row' => $o, 'day' => $split['day'], 'night' => $split['night']];
            });

        $absences = $employee->absences()
            ->whereBetween('date', [$start, $end])
            ->orderBy('date')
            ->get();

        return view('livewire.pages.employee-payroll', [
            'employee' => $employee,
            'monthLabel' => Carbon::parse($this->month . '-01')->isoFormat('MMMM YYYY'),
            'calc' => app(PayrollCalculator::class)->compute($employee, $this->month),
            'overtimes' => $overtimes,
            'absences' => $absences,
            'payslip' => $employee->payslipForPeriod($this->month),
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function monthWindow(): array
    {
        $start = Carbon::parse($this->month . '-01')->startOfMonth();

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }
}
