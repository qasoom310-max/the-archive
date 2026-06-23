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

    {{-- Diagnostic banner. If NO categories route to this station the KDS
         can never have tickets — surface that as a friendly, actionable
         message instead of an "empty board" mystery. Only renders when
         the wiring is missing; once an admin assigns even one category
         the banner disappears. --}}
    @if ($routedCategories->isEmpty())
        <div class="mx-3 my-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 shadow-sm">
            <p class="font-bold">⚠️ {{ __('No categories are routed to this station yet.') }}</p>
            <p class="mt-1">
                {{ __('Open POS → Categories, edit each one that belongs here, and set "Kitchen station" to') }}
                <span class="font-semibold">{{ $stationLabel }}</span>.
                {{ __('Until then no sale will ever appear on this screen.') }}
            </p>
            <a href="{{ url('/app/pos/category') }}"
               class="mt-3 inline-flex items-center gap-2 rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-700">
                {{ __('Go to Categories') }} →
            </a>
        </div>
    @endif

    {{-- 3-column kanban. Mobile-first stack; sm+ side-by-side. Each column
         scrolls vertically. Large gaps + bold colour bands per status so a
         distracted cook can read state at a glance. --}}
    <div class="grid min-h-0 flex-1 grid-cols-1 gap-2 overflow-y-auto p-2 sm:grid-cols-3 sm:gap-3 sm:overflow-hidden sm:p-3">
        @foreach ($columns as $column)
            @php
                $tone = $columnTone[$column->color()];
                $columnTickets = $tickets->where('status', $column);
            @endphp

            {{-- The NEW (Pending) column glows continuously while it holds any
                 un-accepted order, and stops the moment it empties (the cook
                 tapped "Start preparing", moving the last ticket to Preparing).
                 Driven by the server-rendered ticket count so it's always in
                 sync — no JS timer to drift. --}}
            <div class="flex min-h-0 flex-col rounded-xl ring-1 ring-chrome-900/5 {{ $tone['bg'] }} @if ($column === \Modules\Pos\Enums\PrepStatus::Pending && $columnTickets->count() > 0) kds-new-glow @endif">
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
                                    @if ($ticket->tableName !== null)
                                        <p class="mt-1 inline-flex items-center gap-1 rounded-md bg-chrome-900 px-2 py-0.5 text-xs font-bold text-white">
                                            <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v2H3V5Zm14 4H3v1a1 1 0 0 0 1 1v4a1 1 0 1 0 2 0v-4h8v4a1 1 0 1 0 2 0v-4a1 1 0 0 0 1-1V9Z" /></svg>
                                            {{ __('Table') }} {{ $ticket->tableName }}@if ($ticket->floorName !== null) <span class="font-normal text-white/80">· {{ $ticket->floorName }}</span>@endif
                                        </p>
                                    @endif
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
                                        @if (!empty($line->condiments))
                                            <p class="ms-9 mt-1 text-sm font-semibold text-primary-700">
                                                + {{ collect($line->condiments)->pluck('name')->join('، ') }}
                                            </p>
                                        @endif
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
                                            wire:click="markOrderPreparing({{ $ticket->orderId }})"
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
    // Web Audio "ping" — generated in code so we don't ship an audio
    // file (and skip the autoplay round trips <audio src=…> triggers).
    // AudioContext only starts running after a user gesture, so the
    // "Tap to enable sound" button kicks it off; once unlocked it stays
    // alive across polls (we call .resume() defensively before each
    // ping because some browsers — esp. Safari/iOS — drop the context
    // back to `suspended` on visibility changes).
    document.addEventListener('alpine:init', () => {
        Alpine.data('kitchenDisplay', (initialIds) => ({
            prevIds: new Set(initialIds),
            audioCtx: null,
            audioReady: false,

            init() {
                // Use Livewire 3's `commit` hook (fires once per server
                // round-trip, including wire:poll) instead of `morph.updated`.
                // The earlier `morph.updated` choice was unreliable: that
                // hook fires per CHANGED element, but a brand-new ticket
                // arrives as a NEW <article> (handled by `morph.added`),
                // so the diff check never ran for the case we cared about.
                // `commit.succeed` fires after the DOM is fully patched —
                // every fresh poll lands here, and the queryselector below
                // reads the post-patch ticket list.
                Livewire.hook('commit', ({ component, succeed }) => {
                    // Scope to THIS component instance — the page might host
                    // other Livewire components and we only want our polls.
                    const root = this.$root;
                    const wireId = root?.closest('[wire\\:id]')?.getAttribute('wire:id');
                    if (!wireId || component.id !== wireId) return;

                    succeed(() => {
                        const fresh = this.readActiveIds();
                        let isNew = false;
                        for (const id of fresh) {
                            if (!this.prevIds.has(id)) {
                                isNew = true;
                                break;
                            }
                        }
                        this.prevIds = new Set(fresh);
                        // Audio only on a genuinely new arrival. The visual glow
                        // is server-driven (the NEW column carries `kds-new-glow`
                        // whenever it holds a ticket), so JS owns just the ping.
                        if (isNew) {
                            this.ping();
                        }
                    });
                });

                // Some browsers suspend a backgrounded AudioContext. Resume
                // it the moment the tab becomes visible again so a ping
                // that fires seconds later actually plays.
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible'
                        && this.audioCtx
                        && this.audioCtx.state === 'suspended') {
                        this.audioCtx.resume();
                    }
                });
            },

            readActiveIds() {
                // Re-derive from the rendered cards' `wire:key`. Scope to
                // THIS component's DOM root so two KDS tabs open at once
                // can't bleed ids into each other.
                const ids = [];
                const root = this.$root || document;
                root.querySelectorAll('article[wire\\:key^="ticket-"]').forEach((el) => {
                    const key = el.getAttribute('wire:key');
                    const id = parseInt(key.replace('ticket-', ''), 10);
                    if (!Number.isNaN(id)) ids.push(id);
                });
                return ids;
            },

            enableAudio() {
                try {
                    if (!this.audioCtx) {
                        const AC = window.AudioContext || window.webkitAudioContext;
                        this.audioCtx = new AC();
                    }
                    // .resume() returns a Promise — wait so the
                    // acknowledgement ping below actually plays.
                    Promise.resolve(this.audioCtx.resume()).then(() => {
                        this.audioReady = this.audioCtx.state === 'running';
                        if (this.audioReady) {
                            this.ping(880, 0.08); // confirmation beep
                        }
                    });
                } catch (e) {
                    this.audioReady = false;
                }
            },

            ping(freq = 1040, duration = 0.22) {
                if (!this.audioCtx) return;
                // Defensive resume — Safari/iOS can suspend silently
                // even mid-page. Calling .resume() on a `running` ctx
                // is a no-op, so this is cheap.
                if (this.audioCtx.state === 'suspended') {
                    this.audioCtx.resume();
                }
                const ctx = this.audioCtx;
                const t0 = ctx.currentTime;

                // Two short tones back-to-back — easier to distinguish from
                // ambient kitchen noise than a single beep.
                const tones = [freq, freq * 1.5];
                tones.forEach((f, i) => {
                    const start = t0 + i * (duration + 0.04);
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.type = 'sine';
                    osc.frequency.value = f;
                    gain.gain.setValueAtTime(0.0001, start);
                    gain.gain.exponentialRampToValueAtTime(0.5, start + 0.015);
                    gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);
                    osc.connect(gain).connect(ctx.destination);
                    osc.start(start);
                    osc.stop(start + duration + 0.02);
                });
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

    /* Un-accepted order cue — the NEW (Pending) column glows with a warm
       amber pulse that LOOPS for as long as the column holds a ticket, so an
       un-started order keeps drawing the eye (even on a muted tablet) until
       the cook taps "Start preparing". Amber matches the NEW header band;
       outer glow (not inset) so it reads as a "glow". */
    @keyframes kdsNewGlow {
        0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); }
        50%      { box-shadow: 0 0 26px 5px rgba(245, 158, 11, 0.9), 0 0 0 3px rgba(245, 158, 11, 0.55); }
    }
    .kds-new-glow {
        animation: kdsNewGlow 1.1s ease-in-out infinite;
        border-radius: 0.75rem; /* match rounded-xl so the glow hugs the corners */
    }
</style>
