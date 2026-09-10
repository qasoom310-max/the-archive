<?php

declare(strict_types=1);

namespace App\Livewire\Pages;

use App\Erp\Calendar\AdPlanner;
use App\Erp\Calendar\EventWindow;
use App\Erp\Calendar\KnownEvents;
use App\Erp\Settings\Setting;
use App\Models\CalendarEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The ad calendar for THIS database: last year's sales as a heatmap, the
 * selling windows ahead with the date ads must be live by, and the rules
 * (lead days, which countries' holidays count, the owner's own seasons)
 * that shape both. Admin-only — it is a strategy tool, not a work screen.
 */
#[Layout('components.layouts.app')]
#[Title('Ad calendar')]
final class AdCalendar extends Component
{
    /** @var array<string, string> source → lead days, as typed */
    public array $leadDays = [];

    /** @var list<string> */
    public array $markets = [];

    public bool $addingEvent = false;

    public ?int $editingId = null;

    public string $eventName = '';

    public string $eventStart = '';

    public string $eventEnd = '';

    public string $eventKind = EventWindow::KIND_CUSTOM;

    public bool $eventRecurs = true;

    public string $eventNotes = '';

    public function mount(AdPlanner $planner): void
    {
        $this->guardAdmin();

        foreach ($planner->leadDays() as $source => $days) {
            $this->leadDays[$source] = (string) $days;
        }
        $this->markets = $planner->markets();
    }

    private function guardAdmin(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);
    }

    public function saveSettings(AdPlanner $planner): void
    {
        $this->guardAdmin();

        $this->validate([
            'leadDays.*' => ['required', 'integer', 'min:0', 'max:365'],
            'markets' => ['required', 'array', 'min:1'],
            'markets.*' => [Rule::in(array_keys(KnownEvents::COUNTRIES))],
        ]);

        $planner->setLeadDays(array_map('intval', $this->leadDays));
        $planner->setMarkets(array_values($this->markets));

        session()->flash('adcal_toast', __('Rules saved.'));
    }

    public function openEvent(?int $id = null, ?string $start = null, ?string $end = null): void
    {
        $this->guardAdmin();
        $this->resetValidation();

        $event = $id !== null ? CalendarEvent::query()->find($id) : null;

        if ($event !== null) {
            $this->editingId = $event->id;
            $this->eventName = $event->name;
            $this->eventStart = $event->start_date->toDateString();
            $this->eventEnd = $event->end_date->toDateString();
            $this->eventKind = $event->kind;
            $this->eventRecurs = $event->recurs;
            $this->eventNotes = (string) $event->notes;
        } else {
            $this->editingId = null;
            $this->eventName = '';
            $this->eventStart = (string) $start;
            $this->eventEnd = (string) $end;
            $this->eventKind = EventWindow::KIND_CUSTOM;
            $this->eventRecurs = true;
            $this->eventNotes = '';
        }

        $this->addingEvent = true;
    }

    public function closeEvent(): void
    {
        $this->addingEvent = false;
        $this->editingId = null;
    }

    public function saveEvent(): void
    {
        $this->guardAdmin();

        $this->validate([
            'eventName' => ['required', 'string', 'max:120'],
            'eventStart' => ['required', 'date'],
            'eventEnd' => ['required', 'date', 'after_or_equal:eventStart'],
            'eventKind' => [Rule::in([EventWindow::KIND_CUSTOM, EventWindow::KIND_CLOSED])],
            'eventNotes' => ['nullable', 'string', 'max:255'],
        ]);

        $attributes = [
            'name' => trim($this->eventName),
            'start_date' => $this->eventStart,
            'end_date' => $this->eventEnd,
            'kind' => $this->eventKind,
            'recurs' => $this->eventRecurs,
            'notes' => trim($this->eventNotes) !== '' ? trim($this->eventNotes) : null,
        ];

        if ($this->editingId !== null) {
            CalendarEvent::query()->whereKey($this->editingId)->update($attributes);
        } else {
            CalendarEvent::query()->create($attributes);
        }

        $this->closeEvent();
        session()->flash('adcal_toast', __('Event saved.'));
    }

    public function deleteEvent(int $id): void
    {
        $this->guardAdmin();

        CalendarEvent::query()->whereKey($id)->delete();
        if ($this->editingId === $id) {
            $this->closeEvent();
        }

        session()->flash('adcal_toast', __('Event removed.'));
    }

    public function render(AdPlanner $planner): View
    {
        $today = CarbonImmutable::today();

        return view('livewire.pages.ad-calendar', [
            'companyName' => (string) Setting::get('company.name', config('app.name')),
            'sources' => $planner->sources(),
            'sourceLabels' => [
                'rental' => __('Rent A Car'),
                'limousine' => __('Limousine'),
                'pos' => __('Point of Sale'),
            ],
            'countries' => KnownEvents::COUNTRIES,
            'heatmap' => $planner->heatmap($today),
            'plan' => $planner->plan($today),
            'unnamed' => $planner->unnamed($today),
            'customEvents' => Schema::hasTable('calendar_events')
                ? CalendarEvent::query()->orderBy('start_date')->get()
                : collect(),
            'today' => $today->toDateString(),
        ]);
    }
}
