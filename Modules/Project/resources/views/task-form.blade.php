<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/project/task') }}" wire:navigate class="hover:text-primary-700">{{ __('Tasks') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $task?->title ?? __('New task') }}</span>
    </div>

    <livewire:views.form-view
        :model="\Modules\Project\Models\ProjectTask::class"
        model-key="project.task"
        :record-id="$task?->id"
        :title="$task ? __('Edit task') : __('New task')"
        :key="'task-form-' . ($task?->id ?? 'new')" />
</div>
