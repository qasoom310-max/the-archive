<div>
    @if ($requests->isNotEmpty())
        <div class="mb-6 rounded-xl bg-amber-50 p-4 ring-1 ring-amber-200">
            <h2 class="text-sm font-semibold text-amber-900">{{ __('Staff app: account deletion requests') }}</h2>
            <p class="mt-1 text-sm text-amber-800">
                {{ __('Delete the account below (or pause it if the law or the accounts need it kept), then mark the request done. The privacy policy promises it within 30 days.') }}
            </p>

            <ul class="mt-3 divide-y divide-amber-200 rounded-lg bg-white ring-1 ring-amber-200">
                @foreach ($requests as $request)
                    <li class="flex flex-wrap items-start justify-between gap-3 px-3 py-3 text-sm" wire:key="deletion-{{ $request->id }}">
                        <div class="min-w-0">
                            <p class="font-semibold text-chrome-800">{{ $request->name }} · <span class="font-normal text-chrome-600">{{ $request->login }}</span></p>
                            <p class="text-xs text-chrome-500">
                                {{ $request->source === \App\Models\AppDeletionRequest::SOURCE_APP ? __('Asked in the app') : __('Asked on the web page') }}
                                · {{ $request->created_at?->diffForHumans() }}
                                @if ($request->business)
                                    · {{ $request->business }}
                                @endif
                                @if ($request->contact_email)
                                    · {{ __('Confirm to :email', ['email' => $request->contact_email]) }}
                                @endif
                            </p>
                            @if ($request->note)
                                <p class="mt-1 whitespace-pre-line text-chrome-700">{{ $request->note }}</p>
                            @endif
                        </div>
                        <button type="button" wire:click="markDone({{ $request->id }})"
                            wire:confirm="{{ __('Mark this request done? Do it once the account is deleted.') }}"
                            class="shrink-0 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-chrome-700 ring-1 ring-chrome-300 hover:bg-chrome-50">
                            {{ __('Mark done') }}
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
