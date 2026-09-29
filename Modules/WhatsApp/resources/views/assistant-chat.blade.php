<div class="mx-auto flex h-[calc(100vh-8rem)] max-w-3xl flex-col p-4 sm:p-6">
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">Assistant chat</h1>
            <p class="text-sm text-chrome-500">Try the WhatsApp staff assistant here — the same bot, the same rules, no phone needed.</p>
        </div>
        <a href="{{ url('/app/settings/whatsapp-assistant') }}" wire:navigate class="text-sm font-medium text-primary-700 hover:underline">Settings</a>
    </div>

    @unless ($ready)
        <div class="mb-4 rounded-lg bg-amber-50 px-4 py-2 text-sm font-medium text-amber-700 ring-1 ring-amber-200">
            The assistant isn't switched on, or has no Claude API key set, so messages will get a
            "not available" reply. Fix that on the
            <a href="{{ url('/app/settings/whatsapp-assistant') }}" wire:navigate class="underline">Settings</a> tab.
        </div>
    @endunless

    {{-- Long-term memory: what the assistant keeps past the recent chat. It
         saves a note when asked to remember something; deleting one here is
         the same as telling it to forget. --}}
    <details class="mb-4 rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5" data-assistant-memory>
        <summary class="cursor-pointer select-none px-4 py-2 text-sm font-medium text-chrome-800">
            Saved notes ({{ $memories->count() }})
            <span class="font-normal text-chrome-500">— what the assistant remembers for you. Say "remember …" to add one.</span>
        </summary>
        <div class="max-h-48 space-y-2 overflow-y-auto border-t border-chrome-100 px-4 py-3">
            @forelse ($memories as $memory)
                <div class="flex items-start gap-2" wire:key="memory-{{ $memory->id }}">
                    <p class="flex-1 whitespace-pre-wrap text-sm text-chrome-800" dir="auto">{{ $memory->text }}</p>
                    <button type="button" wire:click="forgetMemory({{ $memory->id }})"
                            wire:confirm="Forget this note?"
                            class="shrink-0 text-xs font-medium text-red-600 hover:underline">Forget</button>
                </div>
            @empty
                <p class="text-sm text-chrome-500">Nothing saved yet.</p>
            @endforelse
        </div>
    </details>

    <div class="mb-4 flex-1 space-y-3 overflow-y-auto rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
        @forelse ($history as $turn)
            <div class="flex {{ $turn['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                <div
                    class="max-w-[80%] whitespace-pre-wrap rounded-2xl px-4 py-2 text-sm {{ $turn['role'] === 'user' ? 'bg-primary-400 text-chrome-900' : 'bg-chrome-100 text-chrome-800' }}"
                    dir="auto"
                >{{ $turn['text'] }}</div>
            </div>
        @empty
            <p class="text-sm text-chrome-400">Send a message the way a staff member would — a trip, a booking number, or just "hi".</p>
        @endforelse

        @if ($downloadUrl !== null)
            <div class="flex justify-start">
                {{-- A plain link, NOT wire:navigate — this is a file download, not a page. --}}
                <a href="{{ $downloadUrl }}" class="rounded-lg bg-chrome-100 px-3 py-2 text-xs font-medium text-chrome-700 hover:bg-chrome-200">
                    ⬇ Download {{ $pendingDownloadFilename }}
                </a>
            </div>
        @endif
    </div>

    <form wire:submit="send" class="flex items-end gap-2">
        {{-- Enter sends on a desktop (Shift+Enter for a new line). On a phone
             Enter stays a new line and the Send button sends — a touch keyboard
             has no Shift to reach for. Desktop = a mouse that can hover, the
             same test the idle sign-out uses. --}}
        <textarea
            wire:model="text"
            x-data
            x-on:keydown.enter="if (! $event.shiftKey && ! $event.isComposing && window.matchMedia('(pointer: fine) and (hover: hover)').matches) { $event.preventDefault(); $el.form.requestSubmit(); }"
            data-enter-sends-on-desktop
            rows="2"
            class="o-input flex-1 resize-none"
            placeholder="Type a message…"
            dir="auto"
        ></textarea>
        <button type="submit" class="o-btn-primary" wire:loading.attr="disabled" wire:target="send">
            <span wire:loading.remove wire:target="send">Send</span>
            <span wire:loading wire:target="send">…</span>
        </button>
    </form>
    @error('text')
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
