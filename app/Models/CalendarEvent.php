<?php

declare(strict_types=1);

namespace App\Models;

use App\Erp\Calendar\EventWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * A selling window the owner named for THIS database — F1 weekend, wedding
 * season, a closure. The computed Islamic and national windows live in
 * {@see \App\Erp\Calendar\KnownEvents}; this table holds what only the owner
 * knows.
 *
 * @property int $id
 * @property string $name
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string $kind
 * @property bool $recurs
 * @property string|null $notes
 */
final class CalendarEvent extends Model
{
    protected $table = 'calendar_events';

    /** @var list<string> */
    protected $fillable = ['name', 'start_date', 'end_date', 'kind', 'recurs', 'notes'];

    /** @var array<string, mixed> */
    protected $attributes = ['kind' => EventWindow::KIND_CUSTOM, 'recurs' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'recurs' => 'boolean'];
    }

    public function isClosed(): bool
    {
        return $this->kind === EventWindow::KIND_CLOSED;
    }

    /**
     * Every owner-defined window overlapping [$from, $to]. A recurring one is
     * projected onto each year in range, so "wedding season" entered once
     * keeps showing up.
     *
     * @return list<EventWindow>
     */
    public static function windowsBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('calendar_events')) {
            return [];
        }

        $out = [];

        foreach (self::query()->orderBy('start_date')->get() as $event) {
            $start = CarbonImmutable::instance($event->start_date)->startOfDay();
            $end = CarbonImmutable::instance($event->end_date)->startOfDay();
            $span = (int) $start->diffInDays($end);

            $years = $event->recurs
                ? range((int) $from->format('Y') - 1, (int) $to->format('Y') + 1)
                : [(int) $start->format('Y')];

            foreach ($years as $year) {
                $s = $event->recurs ? $start->setYear($year) : $start;
                $e = $s->addDays($span);

                $window = new EventWindow(
                    'custom:' . $event->id,
                    $event->name,
                    $s,
                    $e,
                    $event->kind,
                    EventWindow::COUNTRY_GCC,
                    false,
                    (int) $event->id,
                );

                if ($window->overlaps($from, $to)) {
                    $out[] = $window;
                }
            }
        }

        usort($out, static fn (EventWindow $a, EventWindow $b): int => $a->start <=> $b->start);

        return $out;
    }
}
