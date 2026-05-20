<div class="mx-auto max-w-7xl p-6">
    <div class="mb-6">
        <h1 class="text-xl font-bold text-chrome-900">Dashboard</h1>
        <p class="text-sm text-chrome-500">Welcome back, Administrator. Press
            <kbd class="rounded border border-chrome-300 bg-chrome-100 px-1 text-xs">⌘K</kbd>
            to jump anywhere.</p>
    </div>

    {{-- KPI tiles --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        @foreach ([
            ['Installed apps', $appCount, 'bg-primary-600'],
            ['Installed modules', $moduleCount, 'bg-emerald-600'],
            ['Registered models', $modelCount, 'bg-sky-600'],
        ] as [$label, $value, $color])
            <div class="flex items-center gap-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                <span class="flex size-10 items-center justify-center rounded-lg {{ $color }} text-white">
                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor"><path d="M3 4h14v3H3V4Zm0 5h14v3H3V9Zm0 5h14v3H3v-3Z"/></svg>
                </span>
                <div>
                    <p class="text-2xl font-bold text-chrome-900">{{ $value }}</p>
                    <p class="text-xs uppercase tracking-wide text-chrome-400">{{ $label }}</p>
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <section class="lg:col-span-2">
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-chrome-900/5">
                <h2 class="mb-2 text-sm font-semibold text-chrome-800">Getting started</h2>
                <p class="text-sm text-chrome-500">
                    This is the Odoo-style application shell. Use the grid icon (top-left) to switch
                    apps, the sidebar to navigate within a module, and the Chatter on the right to
                    follow a record's history and schedule activities.
                </p>
                <ul class="mt-4 space-y-2 text-sm text-chrome-600">
                    <li>• <code class="rounded bg-chrome-100 px-1">php artisan module:list</code> — see modules</li>
                    <li>• <code class="rounded bg-chrome-100 px-1">php artisan module:install &lt;name&gt;</code> — install one</li>
                </ul>
                <a href="{{ url('/playground') }}" class="o-btn-primary mt-4">
                    Open the View Engine playground →
                </a>
            </div>
        </section>

        <section>
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-chrome-800">Chatter</h2>
                    @if ($ticket)
                        <span class="o-chip bg-chrome-100 text-chrome-500">{{ $ticket->subject }}</span>
                    @endif
                </div>
                @if ($ticket)
                    <livewire:chatter :record="$ticket" :key="'chatter-'.$ticket->id" />
                @else
                    <p class="py-8 text-center text-sm text-chrome-400">
                        No demo record. Run <code class="rounded bg-chrome-100 px-1">php artisan db:seed</code>.
                    </p>
                @endif
            </div>
        </section>
    </div>
</div>
