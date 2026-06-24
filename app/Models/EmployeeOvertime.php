<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One overtime session — a worked interval on `work_date`. End ≤ start means it
 * ran past midnight into the next day.
 *
 * @property int $id
 * @property int $employee_id
 * @property Carbon $work_date
 * @property string $start_time  'HH:MM'
 * @property string $end_time    'HH:MM'
 * @property string|null $notes
 */
final class EmployeeOvertime extends Model
{
    /** @var list<string> */
    protected $fillable = ['employee_id', 'work_date', 'start_time', 'end_time', 'notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['work_date' => 'date'];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The worked interval as [start, end] Carbon instants. When the clock-out
     * is not after the clock-in, the session crossed midnight, so end rolls to
     * the next day.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function interval(): array
    {
        $date = $this->work_date instanceof Carbon ? $this->work_date->copy() : Carbon::parse((string) $this->work_date);

        $start = $date->copy()->setTimeFromTimeString($this->start_time);
        $end = $date->copy()->setTimeFromTimeString($this->end_time);

        if ($end <= $start) {
            $end = $end->addDay();
        }

        return [$start, $end];
    }
}
