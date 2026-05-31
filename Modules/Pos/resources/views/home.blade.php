@php $money = fn ($v) => \App\Erp\Money\Currencies::format($v); @endphp

<div class="mx-auto max-w-5xl p-4 sm:p-6">
    <div class="mb-6">
        <h1 class="text-xl font-bold text-chrome-900">{{ __('Point of Sale') }}</h1>
        <p class="text-sm text-chrome-500">
            {{ __('One shared register for the whole store — every cashier sells into the same session.') }}
        </p>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            @if ($active)
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Register is open') }}</h2>
                <p class="mt-1 text-xs text-chrome-400">
                    {{ $active->reference }} · {{ __('float') }} {{ $money($active->opening_cash) }} ·
                    {{ __('opened') }} {{ $active->opened_at?->isoFormat('MMM D, HH:mm') }}
                </p>
                <p class="mt-1 text-xs text-chrome-400">
                    {{ __('Opened by') }} {{ $active->user?->name ?? __('Unknown') }}
                </p>
                <a href="{{ url('/app/pos/session/' . $active->id . '/terminal') }}" wire:navigate
                    class="o-btn-primary mt-4 w-full justify-center">{{ __('Resume selling') }}</a>
                <a href="{{ url('/app/pos/session/' . $active->id) }}" wire:navigate
                    class="o-btn-ghost mt-2 w-full justify-center">{{ __('Manage register') }}</a>
            @else
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Open the register') }}</h2>
                <p class="mt-1 text-xs text-chrome-400">{{ __('No session is open. Opening starts the shared register for everyone.') }}</p>
                <label class="mt-3 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Opening cash float') }}</label>
                <input type="number" step="0.01" wire:model="openingCash" class="o-input mt-1">
                <button wire:click="openSession" class="o-btn-primary mt-3 w-full justify-center">{{ __('Open session') }}</button>
            @endif

            {{-- Kitchen Display deep-links. The same component drives both
                 screens — the URL `station` segment parameterises whether
                 it shows food or shisha tickets. Each device pins one. --}}
            <div class="mt-5 grid grid-cols-2 gap-2">
                <a href="{{ url('/app/pos/kitchen/kitchen') }}" wire:navigate
                    class="flex items-center justify-center gap-2 rounded-lg bg-amber-600 px-3 py-3 text-sm font-semibold text-white shadow-sm hover:bg-amber-700">
                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 9.5A3.5 3.5 0 0 0 5.5 13H9v6h2v-6h3.5a3.5 3.5 0 1 0-3.39-4.4 3.5 3.5 0 1 0-7.22 0A3.5 3.5 0 0 0 2 9.5Z" clip-rule="evenodd"/></svg>
                    {{ __('Kitchen') }}
                </a>
                <a href="{{ url('/app/pos/kitchen/shisha') }}" wire:navigate
                    class="flex items-center justify-center gap-2 rounded-lg bg-fuchsia-600 px-3 py-3 text-sm font-semibold text-white shadow-sm hover:bg-fuchsia-700">
                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a1 1 0 0 0-1 1v2a3 3 0 0 1-3 3H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-2a3 3 0 0 1-3-3V3a1 1 0 0 0-1-1Z"/></svg>
                    {{ __('Shisha') }}
                </a>
            </div>
        </div>

        <div class="lg:col-span-2">
            @if ($active)
                <div wire:poll.30s class="mb-5 rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                    <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Active cashiers') }}</h2>
                    @forelse ($participants as $p)
                        <div wire:key="part-{{ $p->id }}"
                            class="flex items-center justify-between border-b border-chrome-100 py-2 last:border-0">
                            @include('pos::partials.user-chip', ['user' => $p->user])
                            <span class="text-xs text-chrome-400">
                                {{ __('seen') }} {{ $p->last_activity?->diffForHumans() }}
                            </span>
                        </div>
                    @empty
                        <p class="py-3 text-center text-sm text-chrome-400">{{ __('No one is on the register right now.') }}</p>
                    @endforelse
                </div>
            @endif

            @if ($recent->isNotEmpty())
                <h2 class="mb-2 text-sm font-semibold text-chrome-800">{{ __('Recently closed') }}</h2>
                <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
                    <table class="min-w-full divide-y divide-chrome-100 text-sm">
                        <tbody class="divide-y divide-chrome-100">
                            @foreach ($recent as $s)
                                <tr class="hover:bg-chrome-50">
                                    <td class="px-4 py-2">
                                        <a href="{{ url('/app/pos/session/' . $s->id) }}" wire:navigate
                                            class="font-medium text-primary-700 hover:underline">{{ $s->reference }}</a>
                                        <span class="ms-2 text-xs text-chrome-400">{{ $s->user?->name ?? __('Unknown') }}</span>
                                    </td>
                                    <td class="px-4 py-2 text-end text-chrome-500">{{ __('diff') }} {{ $money($s->cash_difference) }}</td>
                                    <td class="px-4 py-2 text-end text-chrome-400">{{ $s->closed_at?->isoFormat('MMM D, HH:mm') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
