<div class="mx-auto max-w-6xl p-4 sm:p-6">
    <div class="mb-6 flex items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Projects') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Pick a project to open its Kanban board.') }}</p>
        </div>
        @if ($canCreate)
            <a href="{{ url('/app/project/project/new') }}" wire:navigate
                class="o-btn-primary shrink-0 text-sm">{{ __('New project') }}</a>
        @endif
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($projects as $project)
            <div wire:key="project-{{ $project->id }}"
                class="relative rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5 transition hover:ring-primary-400">
                {{-- Card body opens the board; pe-16 leaves room for the icons. --}}
                <a href="{{ url('/app/project/' . $project->id . '/board') }}" wire:navigate class="block">
                    <div class="flex items-center gap-3 pe-16">
                        <span class="size-3 shrink-0 rounded-full"
                            style="background: {{ $project->color ?: '#f5ef1a' }}"></span>
                        <h2 class="truncate font-semibold text-chrome-800">{{ $project->name }}</h2>
                    </div>
                    <p class="mt-2 text-xs text-chrome-400">
                        {{ $project->tasks_count }} {{ __('tasks') }}
                    </p>
                </a>

                @if ($canEdit || $canDelete)
                    <div class="absolute end-3 top-3 flex items-center gap-1">
                        @if ($canEdit)
                            <a href="{{ url('/app/project/project/' . $project->id) }}" wire:navigate
                                title="{{ __('Edit project') }}"
                                class="text-chrome-400 transition hover:text-primary-600">
                                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Z" />
                                </svg>
                            </a>
                        @endif
                        @if ($canDelete)
                            <button type="button"
                                wire:click="deleteProject({{ $project->id }})"
                                wire:confirm="{{ __('Delete this project and all its tasks, timesheets and cost lines? This cannot be undone.') }}"
                                title="{{ __('Delete project') }}"
                                class="text-chrome-400 transition hover:text-red-600">
                                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21q.149.222.22.469M19.228 5.79a48.108 48.108 0 0 0-3.478-.397m-12 .562q.249-.247.561-.398a48 48 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                </svg>
                            </button>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <p class="text-sm text-chrome-400">{{ __('No projects yet.') }}</p>
        @endforelse
    </div>
</div>
