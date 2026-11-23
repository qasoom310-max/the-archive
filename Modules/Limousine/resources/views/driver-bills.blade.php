{{-- Bills drivers sent through their trip link, waiting for the office. --}}
@php $money = static fn (float $v, ?string $code = null): string => \App\Erp\Money\Currencies::format($v, $code); @endphp
<div class="mx-auto max-w-5xl p-4 sm:p-6" data-driver-bills>
    <x-form-breadcrumb :parent="__('Expenses')" :parent-url="url('/app/limousine/expense')" :current="__('Bills from drivers')" />

    <div class="mb-5">
        <h1 class="text-2xl font-bold tracking-tight text-chrome-900">{{ __('Bills from drivers') }}</h1>
        <p class="mt-1 text-sm text-chrome-500">{{ __('Bills drivers photographed through the link sent with their trip. Check each one and save it, or set it aside.') }}</p>
        <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900 ring-1 ring-amber-200" data-originals-rule>{{ __('Save a bill only when its original paper has reached the office. The VAT authority asks for the originals.') }}</p>
    </div>

    <div class="mb-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <div class="border-b border-chrome-100 px-5 py-3 text-sm font-semibold text-chrome-800">{{ __('To check') }} <span class="ms-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">{{ $waiting->count() }}</span></div>
        <ul class="divide-y divide-chrome-50">
            @forelse ($waiting as $bill)
                <li class="flex flex-wrap items-center gap-4 px-5 py-3" wire:key="dbill-{{ $bill->id }}" data-driver-bill="{{ $bill->id }}">
                    <a href="{{ url('/app/limousine/expense/driver-bill/' . $bill->id . '/photo') }}" target="_blank" rel="noopener" class="block size-16 shrink-0 overflow-hidden rounded-lg bg-chrome-100 ring-1 ring-chrome-900/10">
                        <img src="{{ url('/app/limousine/expense/driver-bill/' . $bill->id . '/photo') }}" alt="{{ __('The bill') }}" class="size-full object-cover" loading="lazy">
                    </a>
                    <div class="min-w-0 flex-1 text-sm">
                        <div class="font-semibold text-chrome-900">{{ $bill->driver?->name ?? '—' }}</div>
                        <div class="text-xs text-chrome-500">
                            {{ __('Trip') }} {{ $bill->leg?->reference ?? '—' }} · {{ __('sent') }} {{ $bill->created_at?->format('j-M H:i') }}
                            @php $days = (int) $bill->created_at?->diffInDays(now()); @endphp
                            @if ($days >= 2)
                                · <span class="font-semibold text-red-600">{{ __('original not in after :count days', ['count' => $days]) }}</span>
                            @endif
                        </div>
                        <div class="text-xs {{ $bill->readTotal() !== null ? 'text-chrome-700' : 'text-amber-700' }}">
                            @if ($bill->readTotal() !== null)
                                {{ (string) ($bill->draft['payee'] ?? '') }} · {{ $money($bill->readTotal(), (string) ($bill->draft['currency'] ?? 'BHD')) }}
                            @else
                                {{ __('Could not be read: type it from the photo.') }}
                            @endif
                        </div>
                    </div>
                    @if ($canSave)
                        <div class="flex shrink-0 gap-2">
                            <a href="{{ url('/app/limousine/expense/scan') }}?bill={{ $bill->id }}" wire:navigate class="o-btn-primary text-sm" data-check-bill>{{ __('Check and save') }}</a>
                            <button type="button" wire:click="discard({{ $bill->id }})" wire:confirm="{{ __('Set this bill aside? It is kept, but not saved as an expense.') }}" class="o-btn-ghost text-sm">{{ __('Set aside') }}</button>
                        </div>
                    @endif
                </li>
            @empty
                <li class="px-5 py-8 text-center text-sm text-chrome-400">{{ __('No bills waiting.') }}</li>
            @endforelse
        </ul>
    </div>

    @if ($handled->isNotEmpty())
        <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/5">
            <div class="border-b border-chrome-100 px-5 py-3 text-sm font-semibold text-chrome-800">{{ __('Done lately') }}</div>
            <ul class="divide-y divide-chrome-50 text-sm">
                @foreach ($handled as $bill)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-2">
                        <span class="text-chrome-700">{{ $bill->driver?->name ?? '—' }} · {{ __('Trip') }} {{ $bill->leg?->reference ?? '—' }}</span>
                        @if ($bill->status === 'saved' && $bill->expense)
                            <a href="{{ url('/app/limousine/expense/' . $bill->expense_id) }}" wire:navigate class="text-xs font-semibold text-emerald-700 hover:underline">{{ __('Saved as :ref', ['ref' => $bill->expense->reference]) }}</a>
                        @else
                            <span class="text-xs text-chrome-400">{{ __('Set aside') }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
