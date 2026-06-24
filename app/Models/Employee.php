<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A staff member for HR / payroll.
 *
 * @property int $id
 * @property string $name
 * @property string|null $position
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $cpr
 * @property float $basic_salary
 * @property Carbon|null $join_date
 * @property string|null $agreement_path  Uploaded signed contract (public disk)
 * @property bool $active
 * @property int $sequence
 * @property string|null $notes
 */
final class Employee extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name', 'position', 'phone', 'email', 'cpr', 'basic_salary',
        'join_date', 'agreement_path', 'active', 'sequence', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'active' => true,
        'basic_salary' => 0,
        'sequence' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'basic_salary' => 'float',
            'join_date' => 'date',
            'active' => 'boolean',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return HasMany<EmployeeOvertime, $this>
     */
    public function overtimes(): HasMany
    {
        return $this->hasMany(EmployeeOvertime::class);
    }

    /**
     * @return HasMany<EmployeeAbsence, $this>
     */
    public function absences(): HasMany
    {
        return $this->hasMany(EmployeeAbsence::class);
    }

    /**
     * @return HasMany<Payslip, $this>
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * The finalised payslip for a 'YYYY-MM' month, if it exists.
     */
    public function payslipForPeriod(string $period): ?Payslip
    {
        return $this->payslips()->where('period', $period)->first();
    }
}
