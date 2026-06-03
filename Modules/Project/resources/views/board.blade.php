@use('Modules\Project\Enums\KanbanState')

<div class="flex h-[calc(100vh-3rem)] flex-col bg-chrome-100">
    {{-- Board header --}}
    <div class="flex items-center justify-between border-b border-chrome-200 bg-white px-4 py-3">
        <div class="min-w-0">
            <h1 class="truncate text-lg font-bold text-chrome-900">{{ $project->name }}</h1>
            <p class="text-xs text-chrome-500">{{ __('Kanban board') }}</p>
        </div>
        <a href="{{ url('/app/project') }}" wire:navigate class="o-btn-ghost text-xs">{{ __('All projects') }}</a>
    </div>

    {{-- Columns. Native HTML5 drag-and-drop (mirrors the engine KanbanView):
         dragstart stashes the task id, the column/card drop zones call
         $wire.moveTask. Column drop appends (sequence 9999, server-clamped);
         a drop ON a card inserts before it at that card's index. --}}
    <div class="flex flex-1 gap-3 overflow-x-auto p-3">
        @foreach ($stages as $stage)
            @php $stageTasks = $tasksByStage[$stage->id] ?? collect(); @endphp

            <div x-data="{ over: false }"
                @dragover.prevent="@js($canWrite) && (over = true)"
                @dragleave="over = false"
                @drop.prevent="over = false; @js($canWrite) && $wire.moveTask(parseInt($event.dataTransfer.getData('taskId')), {{ $stage->id }}, 9999)"
                :class="over ? 'bg-primary-50 ring-2 ring-primary-300' : 'bg-chrome-200/60'"
                class="flex w-72 shrink-0 flex-col rounded-xl p-2 transition">

                <div class="mb-2 flex items-center justify-between gap-1 px-1" x-data="{ editing: false }">
                    <div class="min-w-0 flex-1">
                        {{-- Double-click the title (or the pencil) to rename in place. --}}
                        <h2 x-show="!editing"
                            @dblclick="@js($canWrite) && (editing = true, $nextTick(() => $refs.stage{{ $stage->id }}.focus()))"
                            class="truncate text-sm font-semibold text-chrome-700">{{ $stage->name }}</h2>
                        <input x-show="editing" x-cloak type="text" value="{{ $stage->name }}"
                            x-ref="stage{{ $stage->id }}"
                            @keydown.enter="$wire.editStage({{ $stage->id }}, $event.target.value); editing = false"
                            @blur="$wire.editStage({{ $stage->id }}, $event.target.value); editing = false"
                            @keydown.escape="editing = false"
                            class="w-full rounded border border-chrome-300 px-2 py-0.5 text-sm font-semibold text-chrome-800 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20">
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <span class="rounded-full bg-white px-2 text-xs font-bold text-chrome-500">{{ $stageTasks->count() }}</span>
                        @if ($canWrite)
                            <button type="button" title="{{ __('Rename') }}"
                                @click="editing = true; $nextTick(() => $refs.stage{{ $stage->id }}.focus())"
                                class="text-chrome-400 transition hover:text-primary-600">
                                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Z" />
                                </svg>
                            </button>
                        @endif
                        @if ($canDelete)
                            <button type="button" title="{{ __('Delete column') }}"
                                wire:click="deleteStage({{ $stage->id }})"
                                wire:confirm="{{ $stageTasks->count() > 0 ? __('Delete this column? Its tasks will move to another column (or be unassigned if none).') : __('Delete this column?') }}"
                                class="text-chrome-400 transition hover:text-red-600">
                                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21q.149.222.22.469M19.228 5.79a48.108 48.108 0 0 0-3.478-.397m-12 .562q.249-.247.561-.398a48 48 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                </svg>
                            </button>
                        @endif
                    </div>
                </div>

                <div class="flex-1 space-y-2 overflow-y-auto">
                    @forelse ($stageTasks as $task)
                        <div wire:key="task-{{ $task->id }}"
                            draggable="{{ $canWrite ? 'true' : 'false' }}"
                            @dragstart="$event.dataTransfer.setData('taskId', '{{ $task->id }}')"
                            @dragover.stop.prevent
                            @drop.stop.prevent="over = false; @js($canWrite) && $wire.moveTask(parseInt($event.dataTransfer.getData('taskId')), {{ $stage->id }}, {{ $loop->index }})"
                            @class([
                                'cursor-grab rounded-lg bg-white p-3 shadow-sm ring-1 ring-chrome-900/5 transition active:cursor-grabbing hover:shadow-md',
                                'border-s-4 border-red-500' => $task->kanban_state === KanbanState::Blocked,
                                'border-s-4 border-emerald-500' => $task->kanban_state === KanbanState::Done,
                            ])>
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-sm font-medium text-chrome-800">{{ $task->title }}</p>
                                @if ($task->priority)
                                    <span class="shrink-0 text-amber-500" title="{{ __('Priority') }}">★</span>
                                @endif
                            </div>

                            @if ($task->kanban_state === KanbanState::Blocked && $task->blocked_reason)
                                <p class="mt-1 text-xs text-red-600">⚠ {{ $task->blocked_reason }}</p>
                            @endif

                            <div class="mt-2 flex items-center justify-between text-xs text-chrome-400">
                                {{-- effective / planned hours; over-budget burns red --}}
                                <span @class(['text-red-500' => $task->remaining_hours < 0])>
                                    {{ number_format($task->effective_hours, 2) }} / {{ number_format($task->planned_hours, 2) }} {{ __('h') }}
                                </span>
                                @if ($task->subtasks_count > 0)
                                    <span title="{{ __('Sub-tasks') }}">☑ {{ $task->subtasks_count }}</span>
                                @endif
                            </div>

                            @if ($task->assignee)
                                <div class="mt-2 flex items-center gap-1.5">
                                    <span class="flex size-5 items-center justify-center rounded-full bg-primary-600 text-[10px] font-bold text-white">
                                        {{ \Illuminate\Support\Str::substr($task->assignee->name, 0, 1) }}
                                    </span>
                                    <span class="truncate text-xs text-chrome-500">{{ $task->assignee->name }}</span>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="px-1 py-6 text-center text-xs text-chrome-400">{{ __('No tasks.') }}</p>
                    @endforelse
                </div>

                @if ($canWrite)
                    {{-- Quick-add a task to the bottom of this column (Enter to submit). --}}
                    <form wire:submit="addTask({{ $stage->id }})" class="mt-2 shrink-0">
                        <input type="text" wire:model="newTaskTitle.{{ $stage->id }}"
                            placeholder="{{ __('+ Add task') }}"
                            class="w-full rounded-lg border border-chrome-200 bg-white/70 px-3 py-1.5 text-sm text-chrome-700 placeholder:text-chrome-400 focus:border-primary-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-primary-500/20">
                    </form>
                @endif
            </div>
        @endforeach

        @if ($canWrite)
            {{-- Add a new Kanban column (Enter to submit). --}}
            <div class="w-64 shrink-0">
                <form wire:submit="addStage"
                    class="rounded-xl border-2 border-dashed border-chrome-300 p-2 transition hover:border-primary-400">
                    <input type="text" wire:model="newStageName"
                        placeholder="{{ __('+ Add stage') }}"
                        class="w-full rounded-lg bg-white px-3 py-1.5 text-sm text-chrome-700 placeholder:text-chrome-400 focus:outline-none focus:ring-2 focus:ring-primary-500/20">
                </form>
            </div>
        @elseif ($stages->isEmpty())
            <div class="m-auto text-center text-sm text-chrome-400">
                {{ __('No stages yet.') }}
            </div>
        @endif
    </div>
</div>
