<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A finalised salary slip (snapshot) for one employee + month.
 *
 * @property int $id
 * @property int $employee_id
 * @property string $period
 * @property float $basic
 * @property float $overtime_pay
 * @property float $absence_deduction
 * @property float $other_deductions
 * @property float $allowances
 * @property float $net
 * @property Carbon|null $paid_on
 * @property string|null $notes
 */
final class Payslip extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'period', 'basic', 'overtime_pay', 'absence_deduction',
        'other_deductions', 'allowances', 'net', 'paid_on', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'basic' => 'float',
            'overtime_pay' => 'float',
            'absence_deduction' => 'float',
            'other_deductions' => 'float',
            'allowances' => 'float',
            'net' => 'float',
            'paid_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
