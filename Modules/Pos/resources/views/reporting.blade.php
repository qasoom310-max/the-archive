<div class="mx-auto max-w-7xl p-6">
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">POS Reporting</h1>
            <p class="text-sm text-chrome-500">Revenue rollups + drill-down into the orders behind them.</p>
        </div>
        <a href="{{ url('/app/pos/order') }}" wire:navigate class="o-btn-ghost">Back to orders</a>
    </div>

    {{-- Preset switcher — same chip pattern as the engine's filter row,
         but it drives BOTH the KPI cards and the embedded list below
         (which we re-key on every preset switch so its inner state
         resets to the new filter). --}}
    <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl bg-white p-2 shadow-sm ring-1 ring-chrome-900/5">
        @foreach ($presets as $p)
            <button type="button" wire:click="setPreset('{{ $p['name'] }}')"
                class="o-chip {{ $activePreset === $p['name'] ? 'bg-primary-600 text-white' : 'bg-chrome-100 text-chrome-600 hover:bg-chrome-200' }}">
                {{ $p['label'] }}
            </button>
        @endforeach
    </div>

    {{-- KPI strip. Three cards, scoped to the active preset, recomputed
         server-side on every preset switch. Only finalised (Done) orders
         count as revenue — drafts and cancels are excluded. --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <p class="text-xs font-semibold uppercase tracking-wide text-chrome-500">Revenue</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-emerald-700">
                {{ \App\Erp\Money\Currencies::format($kpis['revenue']) }}
            </p>
            <p class="text-xs text-chrome-400">finalised orders only</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <p class="text-xs font-semibold uppercase tracking-wide text-chrome-500">Orders</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-chrome-900">
                {{ number_format($kpis['orders']) }}
            </p>
            <p class="text-xs text-chrome-400">in the selected window</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            <p class="text-xs font-semibold uppercase tracking-wide text-chrome-500">Avg. order value</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-chrome-900">
                {{ \App\Erp\Money\Currencies::format($kpis['average']) }}
            </p>
            <p class="text-xs text-chrome-400">revenue ÷ orders</p>
        </div>
    </div>

    {{-- Drill-down: the same engine ListView, but pre-filtered to the
         active preset. Re-keyed by preset so Livewire rebuilds the
         component (and its URL-bound `$filter` prop) on every switch. --}}
    <livewire:views.list-view
        :model="\Modules\Pos\Models\PosOrder::class"
        model-key="pos.order"
        title="Orders in window"
        :filter="$activePreset"
        :key="'reporting-list-' . $activePreset" />
</div>
