@php $money = fn ($v) => number_format((float) $v, 2); @endphp

<div class="mx-auto max-w-5xl p-6">
    <div class="mb-6">
        <h1 class="text-xl font-bold text-chrome-900">Point of Sale</h1>
        <p class="text-sm text-chrome-500">
            One shared register for the whole store — every cashier sells into the same session.
        </p>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            @if ($active)
                <h2 class="text-sm font-semibold text-chrome-800">Register is open</h2>
                <p class="mt-1 text-xs text-chrome-400">
                    {{ $active->reference }} · float {{ $money($active->opening_cash) }} ·
                    opened {{ $active->opened_at?->isoFormat('MMM D, HH:mm') }}
                </p>
                <p class="mt-1 text-xs text-chrome-400">
                    Opened by {{ $active->user?->name ?? 'Unknown' }}
                </p>
                <a href="{{ url('/app/pos/session/' . $active->id . '/terminal') }}" wire:navigate
                    class="o-btn-primary mt-4 w-full justify-center">Resume selling</a>
                <a href="{{ url('/app/pos/session/' . $active->id) }}" wire:navigate
                    class="o-btn-ghost mt-2 w-full justify-center">Manage register</a>
            @else
                <h2 class="text-sm font-semibold text-chrome-800">Open the register</h2>
                <p class="mt-1 text-xs text-chrome-400">No session is open. Opening starts the shared register for everyone.</p>
                <label class="mt-3 block text-xs font-semibold uppercase tracking-wide text-chrome-500">Opening cash float</label>
                <input type="number" step="0.01" wire:model="openingCash" class="o-input mt-1">
                <button wire:click="openSession" class="o-btn-primary mt-3 w-full justify-center">Open session</button>
            @endif
        </div>

        <div class="lg:col-span-2">
            @if ($active)
                <div wire:poll.30s class="mb-5 rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
                    <h2 class="mb-3 text-sm font-semibold text-chrome-800">Active cashiers</h2>
                    @forelse ($participants as $p)
                        <div wire:key="part-{{ $p->id }}"
                            class="flex items-center justify-between border-b border-chrome-100 py-2 last:border-0">
                            @include('pos::partials.user-chip', ['user' => $p->user])
                            <span class="text-xs text-chrome-400">
                                seen {{ $p->last_activity?->diffForHumans() }}
                            </span>
                        </div>
                    @empty
                        <p class="py-3 text-center text-sm text-chrome-400">No one is on the register right now.</p>
                    @endforelse
                </div>
            @endif

            @if ($recent->isNotEmpty())
                <h2 class="mb-2 text-sm font-semibold text-chrome-800">Recently closed</h2>
                <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
                    <table class="min-w-full divide-y divide-chrome-100 text-sm">
                        <tbody class="divide-y divide-chrome-100">
                            @foreach ($recent as $s)
                                <tr class="hover:bg-chrome-50">
                                    <td class="px-4 py-2">
                                        <a href="{{ url('/app/pos/session/' . $s->id) }}" wire:navigate
                                            class="font-medium text-primary-700 hover:underline">{{ $s->reference }}</a>
                                        <span class="ml-2 text-xs text-chrome-400">{{ $s->user?->name ?? 'Unknown' }}</span>
                                    </td>
                                    <td class="px-4 py-2 text-right text-chrome-500">diff {{ $money($s->cash_difference) }}</td>
                                    <td class="px-4 py-2 text-right text-chrome-400">{{ $s->closed_at?->isoFormat('MMM D, HH:mm') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
