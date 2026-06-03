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

                <div class="mb-2 flex items-center justify-between px-1">
                    <h2 class="text-sm font-semibold text-chrome-700">{{ $stage->name }}</h2>
                    <span class="rounded-full bg-white px-2 text-xs font-bold text-chrome-500">{{ $stageTasks->count() }}</span>
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
            </div>
        @endforeach

        @if ($stages->isEmpty())
            <div class="m-auto text-center text-sm text-chrome-400">
                {{ __('No stages yet. Add stages to start the board.') }}
            </div>
        @endif
    </div>
</div>
