<?php

declare(strict_types=1);

namespace App\Erp\Hr;

use App\Models\Employee;
use App\Models\EmployeeAbsence;
use App\Models\EmployeeOvertime;
use Illuminate\Support\Carbon;

/**
 * Computes an employee's pay for a month from their basic salary, overtime
 * sessions (Bahrain bands) and unpaid absences.
 *
 *   hourly rate       = basic ÷ 240   (30 days × 8 h)
 *   overtime pay      = (dayHours×1.2 + nightHours×1.5) × hourly rate
 *   absence deduction = unpaid absence days × (basic ÷ 30)
 *   net               = basic + overtime − absence deduction
 */
final class PayrollCalculator
{
    public const STANDARD_MONTHLY_HOURS = 240.0;

    public const DAYS_IN_MONTH = 30.0;

    public function __construct(private readonly OvertimeCalculator $overtime)
    {
    }

    /**
     * @return array{
     *   basic: float, hourly_rate: float,
     *   ot_day_hours: float, ot_night_hours: float, overtime_pay: float,
     *   absence_days: int, absence_deduction: float, net: float
     * }
     */
    public function compute(Employee $employee, string $period): array
    {
        $basic = (float) $employee->basic_salary;
        $hourly = round($basic / self::STANDARD_MONTHLY_HOURS, 4);

        [$start, $end] = $this->monthWindow($period);

        $dayHours = 0.0;
        $nightHours = 0.0;

        $sessions = $employee->overtimes()
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        foreach ($sessions as $session) {
            /** @var EmployeeOvertime $session */
            [$from, $to] = $session->interval();
            $split = $this->overtime->split($from, $to);
            $dayHours += $split['day'];
            $nightHours += $split['night'];
        }

        $dayHours = round($dayHours, 3);
        $nightHours = round($nightHours, 3);

        $overtimePay = round(
            ($dayHours * OvertimeCalculator::DAY_MULTIPLIER + $nightHours * OvertimeCalculator::NIGHT_MULTIPLIER) * $hourly,
            3,
        );

        $absenceDays = $employee->absences()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('paid', false)
            ->count();

        $perDay = $basic / self::DAYS_IN_MONTH;
        $absenceDeduction = round($absenceDays * $perDay, 3);

        $net = round($basic + $overtimePay - $absenceDeduction, 3);

        return [
            'basic' => round($basic, 3),
            'hourly_rate' => $hourly,
            'ot_day_hours' => $dayHours,
            'ot_night_hours' => $nightHours,
            'overtime_pay' => $overtimePay,
            'absence_days' => $absenceDays,
            'absence_deduction' => $absenceDeduction,
            'net' => $net,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function monthWindow(string $period): array
    {
        $start = Carbon::parse($period . '-01')->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }
}
