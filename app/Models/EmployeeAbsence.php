<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One day an employee was away. `paid = false` (an unpaid absence) deducts a
 * day's pay; `paid = true` (e.g. sick leave) does not.
 *
 * @property int $id
 * @property int $employee_id
 * @property Carbon $date
 * @property string $type   absent | sick
 * @property bool $paid
 * @property string|null $notes
 */
final class EmployeeAbsence extends Model
{
    /** @var list<string> */
    protected $fillable = ['employee_id', 'date', 'type', 'paid', 'notes'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'absent',
        'paid' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'paid' => 'boolean',
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
