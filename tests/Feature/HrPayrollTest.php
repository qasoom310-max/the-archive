<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Hr\OvertimeCalculator;
use App\Erp\Hr\PayrollCalculator;
use App\Erp\Reports\MonthlyFinancials;
use App\Livewire\Pages\EmployeeForm;
use App\Livewire\Pages\EmployeePayroll;
use App\Livewire\Pages\Employees;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * HR / payroll: Bahrain overtime bands (day ×1.2 / night ×1.5), unpaid-absence
 * deduction, payslip finalisation, and the payroll feed into the monthly P&L.
 */
final class HrPayrollTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_overtime_calculator_splits_day_night_and_midnight(): void
    {
        $c = new OvertimeCalculator();

        // Pure day.
        $this->assertSame(['day' => 3.0, 'night' => 0.0], $c->split(Carbon::parse('2026-06-10 09:00'), Carbon::parse('2026-06-10 12:00')));
        // Pure night.
        $this->assertSame(['day' => 0.0, 'night' => 3.0], $c->split(Carbon::parse('2026-06-10 20:00'), Carbon::parse('2026-06-10 23:00')));
        // Straddles 19:00: 1h day + 2h night.
        $this->assertSame(['day' => 1.0, 'night' => 2.0], $c->split(Carbon::parse('2026-06-10 18:00'), Carbon::parse('2026-06-10 21:00')));
        // Crosses midnight, all night: 22:00 → 02:00 = 4h night.
        $this->assertSame(['day' => 0.0, 'night' => 4.0], $c->split(Carbon::parse('2026-06-10 22:00'), Carbon::parse('2026-06-11 02:00')));
        // 18:00 → next 08:00: day 1 (18–19) + 1 (07–08) = 2; night 12 (19–07).
        $this->assertSame(['day' => 2.0, 'night' => 12.0], $c->split(Carbon::parse('2026-06-10 18:00'), Carbon::parse('2026-06-11 08:00')));
    }

    public function test_payroll_computes_overtime_absence_and_feeds_profit(): void
    {
        $month = Carbon::now()->format('Y-m');
        $employee = Employee::query()->create(['name' => 'Ali', 'basic_salary' => 240]); // hourly = 240/240 = 1.0
        $date = Carbon::now()->startOfMonth()->addDays(5)->toDateString();

        Livewire::test(EmployeePayroll::class, ['id' => $employee->id])
            ->set('otDate', $date)->set('otStart', '18:00')->set('otEnd', '21:00') // 1h day + 2h night
            ->call('addOvertime')->assertHasNoErrors()
            ->set('absDate', $date)->set('absType', 'absent')                       // 1 unpaid day
            ->call('addAbsence')->assertHasNoErrors()
            ->call('markPaid');

        $c = app(PayrollCalculator::class)->compute($employee->fresh() ?? $employee, $month);

        // OT pay = (1×1.2 + 2×1.5) × 1.0 = 4.2 ; absence = 240/30 = 8.0 ; net = 240 + 4.2 − 8 = 236.2
        $this->assertSame(4.2, $c['overtime_pay']);
        $this->assertSame(8.0, $c['absence_deduction']);
        $this->assertSame(236.2, $c['net']);

        // Finalised into a payslip…
        $payslip = $employee->payslipForPeriod($month);
        $this->assertNotNull($payslip);
        $this->assertSame(236.2, $payslip->net);

        // …which feeds the monthly P&L payroll line.
        $this->assertSame(236.2, app(MonthlyFinancials::class)->forMonth($month)['payroll']);
    }

    public function test_sick_leave_is_paid_and_does_not_deduct(): void
    {
        $month = Carbon::now()->format('Y-m');
        $employee = Employee::query()->create(['name' => 'Sara', 'basic_salary' => 300]);
        $date = Carbon::now()->startOfMonth()->addDays(3)->toDateString();

        Livewire::test(EmployeePayroll::class, ['id' => $employee->id])
            ->set('absDate', $date)->set('absType', 'sick')
            ->call('addAbsence')->assertHasNoErrors();

        $c = app(PayrollCalculator::class)->compute($employee, $month);
        $this->assertSame(0.0, $c['absence_deduction']);
        $this->assertSame(300.0, $c['net']);
    }

    public function test_employee_form_saves_and_admin_only(): void
    {
        Livewire::test(EmployeeForm::class)
            ->set('form.name', 'Ramadan')
            ->set('form.basic_salary', '350')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('employees', ['name' => 'Ramadan']);

        $this->actingAs(User::factory()->create(['is_admin' => false]));
        Livewire::test(Employees::class)->assertForbidden();
    }
}
