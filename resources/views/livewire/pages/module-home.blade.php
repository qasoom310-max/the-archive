<div class="mx-auto max-w-7xl p-6">
    <div class="mb-6 flex items-center gap-3">
        <span class="flex size-11 items-center justify-center rounded-xl bg-primary-600 text-lg font-bold text-white">
            {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($module->display_name, 0, 2)) }}
        </span>
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ $module->display_name }}</h1>
            <p class="text-sm text-chrome-500">{{ $module->summary ?? 'Application module' }} · v{{ $module->version }}</p>
        </div>
    </div>

    @if ($models->isEmpty())
        <div class="rounded-xl border border-dashed border-chrome-300 bg-white p-10 text-center">
            <p class="text-sm text-chrome-500">
                This module has no registered models yet.
            </p>
            <p class="mt-1 text-xs text-chrome-400">
                Models appear here once the module declares <code class="rounded bg-chrome-100 px-1">DefinesIrModel</code> classes.
            </p>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($models as $model)
                @php
                    $slug = \Illuminate\Support\Str::startsWith($model->model, $module->name . '.')
                        ? \Illuminate\Support\Str::after($model->model, $module->name . '.')
                        : \Illuminate\Support\Str::afterLast($model->model, '.');
                @endphp
                <a href="{{ url('/app/' . $module->name . '/' . str_replace('.', '/', $slug)) }}"
                    class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5 transition hover:ring-primary-300">
                    <p class="text-sm font-semibold text-chrome-800">{{ $model->name }}</p>
                    <p class="mt-1 font-mono text-xs text-chrome-400">{{ $model->model }}</p>
                </a>
            @endforeach
        </div>
    @endif
</div>
