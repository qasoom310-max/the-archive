@php
    /** Static class maps so Tailwind JIT keeps these utilities. */
    $bucketHeader = [
        'overdue'  => 'bg-red-50 text-red-700 ring-red-600/10',
        'today'    => 'bg-amber-50 text-amber-700 ring-amber-600/10',
        'tomorrow' => 'bg-sky-50 text-sky-700 ring-sky-600/10',
        'planned'  => 'bg-chrome-100 text-chrome-600 ring-chrome-500/10',
    ];
    $typeBadge = [
        'comment' => 'bg-primary-50 text-primary-700',
        'note'    => 'bg-amber-50 text-amber-700',
        'log'     => 'bg-chrome-100 text-chrome-500',
    ];
@endphp

<div class="flex flex-col gap-4">
    {{-- Action toolbar --}}
    <div class="flex items-center gap-2 border-b border-chrome-200 pb-3">
        <button type="button" wire:click="$set('mode', 'message')"
            class="o-btn {{ $mode === 'message' ? 'o-btn-primary' : 'o-btn-ghost' }}">
            Send message
        </button>
        <button type="button" wire:click="$set('mode', 'note')"
            class="o-btn {{ $mode === 'note' ? 'o-btn-primary' : 'o-btn-ghost' }}">
            Log note
        </button>
        <button type="button" wire:click="$toggle('showActivityForm')"
            class="o-btn o-btn-ghost">
            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
            Activity
        </button>
    </div>

    {{-- Composer --}}
    <div>
        <textarea wire:model="body" rows="2"
            placeholder="{{ $mode === 'message' ? 'Send a message to followers…' : 'Log an internal note…' }}"
            class="o-input resize-none"></textarea>
        @error('body') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        <div class="mt-2 flex justify-end">
            <button type="button" wire:click="postEntry" class="o-btn-primary">
                {{ $mode === 'message' ? 'Send' : 'Log' }}
            </button>
        </div>
    </div>

    {{-- Schedule-activity form --}}
    @if ($showActivityForm)
        <div class="rounded-lg border border-primary-200 bg-primary-50/40 p-3">
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-primary-700">Schedule activity</p>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <select wire:model="activityTypeId" class="o-input">
                    <option value="">Activity type…</option>
                    @foreach ($activityTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </select>
                <input type="date" wire:model="activityDue" class="o-input">
                <input type="text" wire:model="activitySummary" placeholder="Summary"
                    class="o-input sm:col-span-2">
                <textarea wire:model="activityNote" rows="2" placeholder="Note (optional)"
                    class="o-input resize-none sm:col-span-2"></textarea>
            </div>
            @error('activityTypeId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            @error('activitySummary') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            <div class="mt-2 flex justify-end gap-2">
                <button type="button" wire:click="$set('showActivityForm', false)" class="o-btn-ghost">Discard</button>
                <button type="button" wire:click="scheduleActivity" class="o-btn-primary">Schedule</button>
            </div>
        </div>
    @endif

    {{-- Open activities, bucketed --}}
    @if (count($buckets) > 0)
        <div class="space-y-2">
            @foreach ($buckets as $group)
                @php $b = $group['bucket']; @endphp
                <div class="overflow-hidden rounded-lg ring-1 ring-inset {{ $bucketHeader[$b->value] }}">
                    <div class="flex items-center justify-between px-3 py-1.5 text-xs font-semibold">
                        <span>{{ $b->label() }}</span>
                        <span class="rounded-full bg-white/60 px-2 py-0.5">{{ count($group['items']) }}</span>
                    </div>
                    <ul class="divide-y divide-chrome-100 bg-white">
                        @foreach ($group['items'] as $activity)
                            <li class="flex items-start gap-3 px-3 py-2">
                                <button type="button" wire:click="completeActivity({{ $activity->id }})"
                                    title="Mark done"
                                    class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border border-chrome-300 text-chrome-400 hover:border-emerald-500 hover:bg-emerald-500 hover:text-white">
                                    <svg class="size-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.3 3.29 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
                                </button>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-chrome-800">{{ $activity->summary }}</p>
                                    <p class="text-xs text-chrome-500">
                                        {{ $activity->type?->name ?? 'Activity' }} ·
                                        due {{ $activity->due_date->isoFormat('ddd, DD-MMM') }}
                                        @if ($activity->user_name) · {{ $activity->user_name }} @endif
                                    </p>
                                    @if ($activity->note)
                                        <p class="mt-0.5 text-xs text-chrome-500">{{ $activity->note }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @endif

    {{-- History / log feed --}}
    <div class="space-y-4">
        @forelse ($messages as $msg)
            <div class="flex gap-3">
                <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-400 text-xs font-semibold text-chrome-900">
                    {{ \Illuminate\Support\Str::of($msg->authorLabel())->explode(' ')->map(fn ($p) => \Illuminate\Support\Str::substr($p, 0, 1))->take(2)->implode('') }}
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-chrome-800">{{ $msg->authorLabel() }}</span>
                        <span class="o-chip {{ $typeBadge[$msg->type->value] }}">{{ $msg->type->label() }}</span>
                        <span class="text-xs text-chrome-400">{{ $msg->created_at?->diffForHumans() }}</span>
                    </div>
                    @if ($msg->subject)
                        <p class="text-sm font-medium text-chrome-700">{{ $msg->subject }}</p>
                    @endif
                    <p class="whitespace-pre-line text-sm text-chrome-600">{{ $msg->body }}</p>
                </div>
            </div>
        @empty
            <p class="py-6 text-center text-sm text-chrome-400">No messages yet. Start the conversation above.</p>
        @endforelse
    </div>
</div>
