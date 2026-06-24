<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Erp\Hr\PayrollCalculator;
use App\Models\Employee;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Renders an employee's monthly salary slip as a downloadable PDF (DomPDF).
 * Admin-only. Figures come from {@see PayrollCalculator}; if the month was
 * finalised, the slip also shows its paid date.
 */
final class PayslipPdfController extends Controller
{
    public function __invoke(Request $request, int $id, PayrollCalculator $calculator): Response
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);

        $employee = Employee::query()->findOrFail($id);
        $month = (string) $request->query('month', Carbon::now()->format('Y-m'));

        $calc = $calculator->compute($employee, $month);
        $payslip = $employee->payslipForPeriod($month);

        $pdf = Pdf::loadView('pdf.payslip', [
            'employee' => $employee,
            'calc' => $calc,
            'period' => $month,
            'monthLabel' => Carbon::parse($month . '-01')->isoFormat('MMMM YYYY'),
            'paidOn' => $payslip?->paid_on,
            'generatedAt' => Carbon::now(),
        ])->setPaper('a4');

        $slug = Str::slug($employee->name);

        return $pdf->download("payslip-{$slug}-{$month}.pdf");
    }
}
