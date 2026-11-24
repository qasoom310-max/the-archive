@php
    $tone = match ($state) {
        'on' => 'bg-emerald-50 text-emerald-800',
        'failed', 'stuck', 'off' => 'bg-red-50 text-red-800',
        'held_back', 'take_down', 'queued' => 'bg-amber-50 text-amber-800',
        default => 'bg-chrome-50 text-chrome-700',
    };
    $when = static fn ($at) => $at ? $at->diffForHumans() : '';
@endphp

<div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]" data-website-status="{{ $state }}">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-sm font-semibold text-chrome-800">{{ __('Website') }}</h2>
            <p class="text-xs text-chrome-400">{{ __('Whether this product has reached the online store.') }}</p>
        </div>

        @if ($canSend)
            <button type="button" wire:click="sendNow" wire:loading.attr="disabled" wire:target="sendNow"
                    class="o-btn-primary text-sm" data-website-send>
                <span wire:loading.remove wire:target="sendNow">{{ __('Send to website now') }}</span>
                <span wire:loading wire:target="sendNow">{{ __('Sending…') }}</span>
            </button>
        @endif
    </div>

    <div class="mt-3 rounded-lg px-3 py-2 text-sm {{ $tone }}" data-website-state>
        @switch($state)
            @case('off')
                {{ __('Sending products to the website is switched off, so nothing goes there.') }}
                @if ($isAdmin)
                    <a href="{{ url('/app/settings/woocommerce') }}" wire:navigate class="font-semibold underline">{{ __('Open the website settings') }}</a>
                @endif
                @break
            @case('held_back')
                {{ __('Not sent: the product is not ready for the website. The note under "Show in the online store" says what is missing.') }}
                @break
            @case('take_down')
                {{ __('It is still on the website but should come off. Press "Send to website now" to take it down.') }}
                @break
            @case('taken_down')
                {{ __('Taken off the website.') }} {{ $when($link?->last_synced_at) }}
                @break
            @case('on')
                {{ __('On the website. Last sent :when.', ['when' => $when($link?->last_synced_at)]) }}
                @if ($storeLink)
                    <a href="{{ $storeLink }}" target="_blank" rel="noopener" class="font-semibold underline">{{ __('Open it on the website') }}</a>
                @endif
                @break
            @case('failed')
                <p class="font-semibold">{{ __('The website refused it.') }}</p>
                <p class="mt-1 break-words font-mono text-xs" dir="ltr">{{ $link?->last_error }}</p>
                <p class="mt-1 text-xs">{{ __('Last tried :when.', ['when' => $when($link?->updated_at)]) }}</p>
                @break
            @case('queued')
                {{ __('Waiting to be sent (since :when).', ['when' => $when($link?->updated_at)]) }}
                @break
            @case('stuck')
                {{ __('Still waiting to be sent after :when. The background sender is not running. Press "Send to website now".', ['when' => $when($link?->updated_at)]) }}
                @break
            @default
                {{ __('Not sent to the website yet. Press "Send to website now".') }}
        @endswitch
    </div>

    {{-- A failure is already spelled out in the box above (the link row holds
         the store's reply), so only a success gets its own line. --}}
    @if ($result !== '' && $resultOk)
        <p class="mt-2 break-words text-sm text-emerald-700" data-website-result>{{ $result }}</p>
    @endif
</div>
