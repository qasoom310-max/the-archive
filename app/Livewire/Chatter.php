<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Erp\Chatter\ActivityBucket;
use App\Erp\Chatter\Chatterable;
use App\Models\Mail\MailActivity;
use App\Models\Mail\MailActivityType;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Reusable `mail.thread` Chatter: audit log, notes, messages and the
 * Odoo-19 activity planner (Overdue / Today / Tomorrow / Planned + Done).
 */
final class Chatter extends Component
{
    /** @var class-string<Model&Chatterable> */
    public string $modelClass;

    public int $modelId;

    public string $tab = 'log';

    public string $mode = 'note';

    public string $body = '';

    public bool $showActivityForm = false;

    public ?int $activityTypeId = null;

    public string $activitySummary = '';

    public string $activityDue = '';

    public string $activityNote = '';

    public function mount(Model&Chatterable $record): void
    {
        $this->modelClass = $record::class;
        $this->modelId = (int) $record->getKey();
        $this->activityDue = now()->toDateString();
    }

    #[Computed]
    public function record(): Model&Chatterable
    {
        $record = $this->modelClass::query()->findOrFail($this->modelId);
        assert($record instanceof Chatterable);

        return $record;
    }

    public function postEntry(): void
    {
        $this->validate(['body' => ['required', 'string', 'min:1']]);

        $author = $this->currentUserName();

        $this->mode === 'message'
            ? $this->record()->postMessage($this->body, author: $author)
            : $this->record()->postNote($this->body, $author);

        $this->reset('body');
        unset($this->record);
    }

    public function scheduleActivity(): void
    {
        $this->validate([
            'activityTypeId' => ['required', 'integer', 'exists:mail_activity_types,id'],
            'activitySummary' => ['required', 'string', 'max:255'],
            'activityDue' => ['required', 'date'],
        ]);

        $this->record()->scheduleActivity(
            (int) $this->activityTypeId,
            $this->activitySummary,
            $this->activityDue,
            $this->activityNote !== '' ? $this->activityNote : null,
            $this->currentUserName(),
        );

        $this->reset('activitySummary', 'activityNote', 'showActivityForm');
        $this->activityDue = now()->toDateString();
        unset($this->record);
    }

    public function completeActivity(int $activityId): void
    {
        $activity = $this->record()->activities()
            ->whereKey($activityId)
            ->first();

        if (! $activity instanceof MailActivity) {
            return;
        }

        $activity->markDone($this->currentUserName());
        $this->record()->logChange("Activity done: {$activity->summary}");

        unset($this->record);
    }

    private function currentUserName(): string
    {
        $user = Auth::user();

        return $user instanceof User ? $user->name : 'System';
    }

    /**
     * Open activities grouped into Odoo buckets, ordered Overdue → Planned.
     *
     * @return array<string, array{bucket: ActivityBucket, items: list<MailActivity>}>
     */
    private function activityBuckets(): array
    {
        $order = [
            ActivityBucket::Overdue,
            ActivityBucket::Today,
            ActivityBucket::Tomorrow,
            ActivityBucket::Planned,
        ];

        $grouped = [];

        foreach ($this->record()->openActivities() as $activity) {
            $grouped[$activity->bucket()->value][] = $activity;
        }

        $result = [];

        foreach ($order as $bucket) {
            if (isset($grouped[$bucket->value])) {
                $result[$bucket->value] = [
                    'bucket' => $bucket,
                    'items' => $grouped[$bucket->value],
                ];
            }
        }

        return $result;
    }

    public function render(): View
    {
        $record = $this->record();

        /** @var Collection<int, MailActivityType> $types */
        $types = MailActivityType::query()->orderBy('sequence')->get();

        return view('livewire.chatter', [
            'messages' => $record->messages()->latest()->limit(50)->get(),
            'buckets' => $this->activityBuckets(),
            'doneActivities' => $record->activities()
                ->where('done', true)
                ->latest('done_at')
                ->limit(10)
                ->get(),
            'activityTypes' => $types,
        ]);
    }
}
