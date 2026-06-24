<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <div class="mb-6 flex items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Rent A Car') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Fleet overview & operations.') }}</p>
        </div>
        <a href="{{ url('/app/rental/vehicle/new') }}" wire:navigate class="o-btn-primary shrink-0 text-sm">
            {{ __('New vehicle') }}
        </a>
    </div>

    {{-- Reusable car glyph for the cards. --}}
    @php
        $carIcon = '<path d="M3 9.5 4.2 6.6A2 2 0 0 1 6 5.5h8a2 2 0 0 1 1.8 1.1L17 9.5a2 2 0 0 1 1 1.7V13a1 1 0 0 1-1 1h-1a2 2 0 1 1-4 0H8a2 2 0 1 1-4 0H3a1 1 0 0 1-1-1v-1.8a2 2 0 0 1 1-1.7Z"/><circle cx="6.5" cy="14" r="1.5"/><circle cx="13.5" cy="14" r="1.5"/>';
    @endphp

    {{-- Per-branch availability --}}
    @if ($branches->isNotEmpty())
        <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Availability by branch') }}</h2>
        <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($branches as $branch)
                <div wire:key="branch-{{ $branch->id }}"
                    class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="truncate text-sm font-medium text-chrome-700">{{ $branch->name }}</h3>
                        <span class="shrink-0 rounded bg-sky-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-700">{{ __('Available') }}</span>
                    </div>
                    <div class="mt-3 flex items-end justify-between">
                        <div>
                            <div class="text-3xl font-bold text-chrome-800">{{ $branch->available_count }}<span class="text-base font-medium text-chrome-300"> / {{ $branch->total_count }}</span></div>
                            <div class="text-xs text-chrome-400">{{ __('Cars') }}</div>
                        </div>
                        <svg class="size-7 text-sky-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">{!! $carIcon !!}</svg>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Fleet status KPIs --}}
    <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Fleet status') }}</h2>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        @php
            $cards = [
                ['label' => __('Total Fleet'), 'value' => $total, 'badge' => __('Total'), 'badgeClass' => 'bg-emerald-100 text-emerald-700', 'iconClass' => 'text-emerald-500'],
                ['label' => __('Available'), 'value' => $available, 'badge' => __('Available'), 'badgeClass' => 'bg-sky-100 text-sky-700', 'iconClass' => 'text-sky-500'],
                ['label' => __('Rented'), 'value' => $rented, 'badge' => __('Rented'), 'badgeClass' => 'bg-red-100 text-red-700', 'iconClass' => 'text-red-500'],
                ['label' => __('Under Maintenance'), 'value' => $maintenance, 'badge' => __('Maintenance'), 'badgeClass' => 'bg-amber-100 text-amber-700', 'iconClass' => 'text-amber-500'],
                ['label' => __('Reserved'), 'value' => $reserved, 'badge' => __('Reserved'), 'badgeClass' => 'bg-violet-100 text-violet-700', 'iconClass' => 'text-violet-500'],
            ];
        @endphp
        @foreach ($cards as $card)
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="text-sm font-medium text-chrome-700">{{ $card['label'] }}</h3>
                    <span class="shrink-0 rounded px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $card['badgeClass'] }}">{{ $card['badge'] }}</span>
                </div>
                <div class="mt-3 flex items-end justify-between">
                    <div>
                        <div class="text-3xl font-bold text-chrome-800">{{ $card['value'] }}</div>
                        <div class="text-xs text-chrome-400">{{ __('Cars') }}</div>
                    </div>
                    <svg class="size-7 {{ $card['iconClass'] }}" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">{!! $carIcon !!}</svg>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Masters: clickable tiles for Customers / Vehicles / Drivers / Branches. --}}
    @if (! empty($tiles))
        <div class="mt-8">
            <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Masters') }}</h2>
            @include('partials.module-tiles', ['tiles' => $tiles])
        </div>
    @endif
</div>
