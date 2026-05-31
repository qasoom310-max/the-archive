@php
    $stationLabel = $station->label();

    // Tailwind tone tokens per status — emitted statically (not derived) so
    // the JIT can see every class string. Keys mirror PrepStatus->color().
    $columnTone = [
        'amber'    => ['bg' => 'bg-amber-50',    'header' => 'bg-amber-500',    'badge' => 'bg-amber-100 text-amber-700',    'btn' => 'bg-amber-600 hover:bg-amber-700'],
        'sky'      => ['bg' => 'bg-sky-50',      'header' => 'bg-sky-500',      'badge' => 'bg-sky-100 text-sky-700',        'btn' => 'bg-sky-600 hover:bg-sky-700'],
        'emerald'  => ['bg' => 'bg-emerald-50',  'header' => 'bg-emerald-500',  'badge' => 'bg-emerald-100 text-emerald-700','btn' => 'bg-emerald-600 hover:bg-emerald-700'],
    ];
@endphp

{{-- wire:poll triggers a server-side re-render every 5s; payload diffs
     are reactive. Alpine wrapper watches `activeTicketIds` between
     polls to detect new arrivals and ping the kitchen via a Web Audio
     beep (no audio file, no autoplay restrictions). Wrapping div is
     `h-full overflow-hidden` so the 3 columns scroll internally and
     the topbar stays pinned. --}}
<div wire:poll.5s
     x-data="kitchenDisplay({{ \Illuminate\Support\Js::from($activeTicketIds) }})"
     class="flex h-[calc(100vh-3rem)] flex-col bg-chrome-100">

    {{-- Toolbar --}}
    <div class="flex shrink-0 items-center justify-between gap-3 border-b border-chrome-200 bg-white px-4 py-3 sm:px-6">
        <div class="min-w-0">
            <h1 class="text-lg font-bold text-chrome-900 sm:text-xl">
                {{ __('Kitchen Display') }} — <span class="text-primary-700">{{ $stationLabel }}</span>
            </h1>
            <p class="text-xs text-chrome-500">{{ __('Live — auto-refreshes every 5 seconds.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            {{-- Tap to unmute. Browsers block audio until a user gesture
                 happens on the page; this button satisfies that gesture
                 AND surfaces the muted state so the kitchen knows. --}}
            <button type="button"
                    @click="enableAudio()"
                    :class="audioReady ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-amber-200 animate-pulse'"
                    class="flex h-12 items-center gap-2 rounded-lg px-3 text-sm font-semibold ring-1 transition">
                <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M10 3.75a.75.75 0 0 0-1.264-.55L5.13 6.5H3.75A1.75 1.75 0 0 0 2 8.25v3.5C2 12.716 2.784 13.5 3.75 13.5H5.13l3.606 3.3A.75.75 0 0 0 10 16.25V3.75ZM13.22 6.97a.75.75 0 0 1 1.06 0 5 5 0 0 1 0 7.07.75.75 0 1 1-1.06-1.06 3.5 3.5 0 0 0 0-4.95.75.75 0 0 1 0-1.06Z"/>
                </svg>
                <span x-text="audioReady ? @js(__('Sound on')) : @js(__('Tap to enable sound'))"></span>
            </button>
            <div class="o-chip bg-chrome-100 text-chrome-700">
                {{ count($activeTicketIds) }} {{ __('active') }}
            </div>
        </div>
    </div>

    {{-- 3-column kanban. Mobile-first stack; sm+ side-by-side. Each column
         scrolls vertically. Large gaps + bold colour bands per status so a
         distracted cook can read state at a glance. --}}
    <div class="grid min-h-0 flex-1 grid-cols-1 gap-2 overflow-y-auto p-2 sm:grid-cols-3 sm:gap-3 sm:overflow-hidden sm:p-3">
        @foreach ($columns as $column)
            @php
                $tone = $columnTone[$column->color()];
                $columnTickets = $tickets->where('status', $column);
            @endphp

            <div class="flex min-h-0 flex-col rounded-xl ring-1 ring-chrome-900/5 {{ $tone['bg'] }}">
                {{-- Column header — large + colour-banded for at-a-glance scan. --}}
                <div class="flex items-center justify-between rounded-t-xl px-4 py-3 text-white {{ $tone['header'] }}">
                    <h2 class="text-base font-bold uppercase tracking-wide">{{ $column->label() }}</h2>
                    <span class="inline-flex size-7 items-center justify-center rounded-full bg-white/30 text-sm font-bold">
                        {{ $columnTickets->count() }}
                    </span>
                </div>

                <div class="flex-1 space-y-3 overflow-y-auto p-3">
                    @forelse ($columnTickets as $ticket)
                        @php
                            $elapsed = $ticket->sentAt->diffInMinutes(now());
                            $isLate = $elapsed >= 15;
                        @endphp
                        <article wire:key="ticket-{{ $ticket->orderId }}"
                                 class="rounded-xl bg-white p-4 shadow-sm ring-1 transition {{ $isLate ? 'ring-red-400 animate-late' : 'ring-chrome-900/5' }}">

                            {{-- Header: big order ref + relative time. --}}
                            <header class="mb-3 flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="text-2xl font-bold leading-tight text-chrome-900 tabular-nums">
                                        #{{ \Illuminate\Support\Str::afterLast($ticket->reference, '/') }}
                                    </p>
                                    <p class="text-xs text-chrome-500">{{ $ticket->reference }}</p>
                                </div>
                                <div class="text-end">
                                    <p class="text-sm font-semibold {{ $isLate ? 'text-red-600' : 'text-chrome-700' }}">
                                        {{ $ticket->sentAt->diffForHumans(null, true) }}
                                    </p>
                                    @if ($isLate)
                                        <p class="text-[10px] font-bold uppercase tracking-wide text-red-600">{{ __('Late!') }}</p>
                                    @endif
                                </div>
                            </header>

                            {{-- Item rows. Big qty + name; notes underlined.
                                 Per-line tap target is the WHOLE row + an
                                 explicit "next" pill — touch screens need
                                 fat hit areas because finger taps are messy. --}}
                            <ul class="-mx-2 space-y-1">
                                @foreach ($ticket->lines as $line)
                                    @php
                                        $lineTone = $columnTone[$line->prep_status->color()] ?? $columnTone['amber'];
                                    @endphp
                                    <li class="rounded-lg px-2 py-2 hover:bg-chrome-50">
                                        <div class="flex items-baseline gap-2">
                                            <span class="text-xl font-bold tabular-nums text-chrome-900">{{ rtrim(rtrim(number_format($line->qty, 2), '0'), '.') }}×</span>
                                            <span class="flex-1 text-base font-semibold text-chrome-900">{{ $line->name }}</span>
                                            <span class="o-chip {{ $lineTone['badge'] }}">{{ $line->prep_status?->label() }}</span>
                                        </div>
                                        @if ($line->notes)
                                            <p class="ms-9 mt-1 border-s-2 border-amber-400 ps-2 text-sm font-medium text-amber-800">
                                                ✱ {{ $line->notes }}
                                            </p>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>

                            {{-- Primary action — single big button drives the
                                 ticket through the state machine. min-h-12 so
                                 it's a reliable touch target on a phone. The
                                 secondary "Complete" only appears on a Ready
                                 ticket (the runner just picked it up). --}}
                            <footer class="mt-4 flex gap-2">
                                @if ($column === \Modules\Pos\Enums\PrepStatus::Pending)
                                    <button type="button"
                                            wire:click="markOrderReady({{ $ticket->orderId }})"
                                            class="flex min-h-12 flex-1 items-center justify-center rounded-lg bg-sky-600 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-sky-700 active:scale-[0.98]">
                                        {{ __('Start preparing') }}
                                    </button>
                                @elseif ($column === \Modules\Pos\Enums\PrepStatus::Preparing)
                                    <button type="button"
                                            wire:click="markOrderReady({{ $ticket->orderId }})"
                                            class="flex min-h-12 flex-1 items-center justify-center rounded-lg bg-emerald-600 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700 active:scale-[0.98]">
                                        {{ __('Mark ready') }}
                                    </button>
                                @elseif ($column === \Modules\Pos\Enums\PrepStatus::Ready)
                                    <button type="button"
                                            wire:click="completeOrder({{ $ticket->orderId }})"
                                            wire:confirm="{{ __('Runner picked up #') . \Illuminate\Support\Str::afterLast($ticket->reference, '/') . '?' }}"
                                            class="flex min-h-12 flex-1 items-center justify-center rounded-lg bg-chrome-800 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-chrome-900 active:scale-[0.98]">
                                        {{ __('Complete & dismiss') }}
                                    </button>
                                @endif
                            </footer>
                        </article>
                    @empty
                        <p class="py-12 text-center text-sm text-chrome-400">
                            {{ __('No tickets.') }}
                        </p>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
</div>

@push('scripts')
@endpush

<script>
    // Web Audio "ping" — generated in code so we don't need to ship
    // an audio file (and avoid the autoplay-policy round trips that
    // <audio src=…> triggers). The AudioContext only resumes after a
    // user gesture, so the "Tap to enable sound" button calls
    // `enableAudio()` first; subsequent polls can play freely.
    document.addEventListener('alpine:init', () => {
        Alpine.data('kitchenDisplay', (initialIds) => ({
            prevIds: new Set(initialIds),
            audioCtx: null,
            audioReady: false,

            init() {
                // Track new ticket arrivals across Livewire morphs. The
                // server emits `activeTicketIds` (sorted) on every render
                // — we re-read it from the DOM via x-init lookup. Simpler
                // approach: just intercept Livewire's `morph.updated`.
                Livewire.hook('morph.updated', () => {
                    const fresh = this.readActiveIds();
                    for (const id of fresh) {
                        if (!this.prevIds.has(id)) {
                            this.ping();
                            break; // one beep per poll batch
                        }
                    }
                    this.prevIds = new Set(fresh);
                });
            },

            readActiveIds() {
                // The server-rendered list is embedded in the wrapping
                // element's `x-data` initialiser — but after a morph it's
                // re-emitted with the new ids. Easiest: re-derive from
                // the rendered ticket cards' `wire:key`.
                const ids = [];
                document.querySelectorAll('article[wire\\:key^="ticket-"]').forEach((el) => {
                    const key = el.getAttribute('wire:key');
                    const id = parseInt(key.replace('ticket-', ''), 10);
                    if (!Number.isNaN(id)) ids.push(id);
                });
                return ids;
            },

            enableAudio() {
                if (this.audioCtx) {
                    this.audioReady = true;
                    return;
                }
                try {
                    const AC = window.AudioContext || window.webkitAudioContext;
                    this.audioCtx = new AC();
                    this.audioReady = true;
                    // Play a soft acknowledgement beep so the user knows
                    // the click worked.
                    this.ping(880, 0.08);
                } catch (e) {
                    this.audioReady = false;
                }
            },

            ping(freq = 1040, duration = 0.18) {
                if (!this.audioCtx) return;
                const ctx = this.audioCtx;
                const t0 = ctx.currentTime;
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.0001, t0);
                gain.gain.exponentialRampToValueAtTime(0.4, t0 + 0.01);
                gain.gain.exponentialRampToValueAtTime(0.0001, t0 + duration);
                osc.connect(gain).connect(ctx.destination);
                osc.start(t0);
                osc.stop(t0 + duration + 0.02);
            },
        }));
    });
</script>

<style>
    /* Slow flashing red ring for late tickets. `animate-late` is the hook;
       Tailwind doesn't ship a "slow blink" by default. */
    @keyframes lateFlash {
        0%, 100% { box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.3); }
        50%      { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0.55); }
    }
    .animate-late {
        animation: lateFlash 1.6s ease-in-out infinite;
    }
</style>
