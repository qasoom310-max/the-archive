<div>
    @if ($on)
        <div wire:init="tick" wire:poll.30s="tick" class="fixed bottom-4 end-4 z-40 flex flex-col items-end gap-2 print:hidden" data-abdullah>
            @if ($open)
                <div class="flex max-h-[70vh] w-[calc(100vw-2rem)] max-w-sm flex-col overflow-hidden rounded-2xl bg-white shadow-pop ring-1 ring-chrome-900/10" data-abdullah-panel>
                    <div class="flex items-center gap-2 border-b border-chrome-100 bg-primary-400 px-3 py-2 text-chrome-900">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-chrome-900 text-sm font-bold text-primary-400">ع</span>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-bold leading-tight">{{ __('Abdullah') }}</div>
                            <div class="text-[11px] leading-tight text-chrome-800">{{ __('Your ERP supervisor') }}</div>
                        </div>
                        <select wire:change="setLanguage($event.target.value)" class="rounded-md border-0 bg-white/70 py-0.5 ps-1.5 pe-6 text-xs" aria-label="{{ __('Language') }}">
                            @foreach ($languages as $code => $name)
                                <option value="{{ $code }}" @selected($language === $code)>{{ $name }}</option>
                            @endforeach
                        </select>
                        <button type="button" wire:click="closeBox" class="rounded-md p-1 hover:bg-black/10" aria-label="{{ __('Close') }}">
                            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                        </button>
                    </div>

                    <div class="flex-1 space-y-2 overflow-y-auto bg-chrome-50 p-3" x-data x-init="$el.scrollTop = $el.scrollHeight" x-effect="$el.scrollTop = $el.scrollHeight">
                        @forelse ($messages as $m)
                            @if ($m->role === 'user')
                                <div class="flex justify-end">
                                    <p dir="auto" class="max-w-[85%] whitespace-pre-line rounded-2xl rounded-ee-sm bg-chrome-800 px-3 py-2 text-sm text-white">{{ $m->body }}</p>
                                </div>
                            @else
                                <div class="flex flex-col items-start gap-1" wire:key="abd-{{ $m->id }}">
                                    <p dir="auto" class="max-w-[85%] whitespace-pre-line rounded-2xl rounded-es-sm bg-white px-3 py-2 text-sm text-chrome-800 ring-1 ring-chrome-900/5">{{ $m->body }}</p>
                                    @php $keys = is_array($m->facts['keys'] ?? null) ? $m->facts['keys'] : []; @endphp
                                    @if ($m->kind === 'trip' && array_intersect($keys, $openKeys) !== [])
                                        <button type="button" wire:click="handling({{ $m->id }})" class="rounded-lg bg-primary-400 px-2.5 py-1 text-xs font-semibold text-chrome-900" data-abdullah-handling>{{ __("I'm handling it") }}</button>
                                    @endif
                                </div>
                            @endif
                        @empty
                            <p class="py-6 text-center text-xs text-chrome-400">{{ __('Abdullah is watching. Say hello.') }}</p>
                        @endforelse
                    </div>

                    <form wire:submit="send" class="flex items-end gap-2 border-t border-chrome-100 p-2">
                        <textarea wire:model="text" rows="1" maxlength="1000" dir="auto"
                                  x-on:keydown.enter.prevent="if (! $event.shiftKey) $wire.send()"
                                  class="o-input min-h-[38px] flex-1 resize-none text-sm" placeholder="{{ __('Answer Abdullah…') }}"></textarea>
                        <button type="submit" wire:loading.attr="disabled" wire:target="send" class="o-btn-primary px-3 py-2 text-sm">{{ __('Send') }}</button>
                    </form>
                    <p wire:loading wire:target="send" class="px-3 pb-2 text-[11px] text-chrome-400">{{ __('Abdullah is typing…') }}</p>
                </div>
            @endif

            <button type="button" wire:click="{{ $open ? 'closeBox' : 'openBox' }}"
                    class="relative flex size-12 items-center justify-center rounded-full bg-chrome-900 text-lg font-bold text-primary-400 shadow-pop ring-2 ring-primary-400 transition hover:scale-105"
                    aria-label="{{ __('Abdullah') }}" title="{{ __('Abdullah') }}" data-abdullah-button>
                ع
                @if ($unread > 0 && ! $open)
                    <span class="absolute -top-1 -end-1 flex size-5 items-center justify-center rounded-full bg-red-600 text-[10px] font-bold text-white">{{ $unread }}</span>
                @endif
            </button>
        </div>
    @endif
</div>
