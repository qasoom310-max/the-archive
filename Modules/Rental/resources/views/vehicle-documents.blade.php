{{--
    A car's papers, year by year. Adding 2027 never replaces 2026; a paper
    stays until the owner deletes it. Files are downloaded through a signed-in
    route (single file, or a whole year / everything as a ZIP).
--}}
<div class="mb-5 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]" data-car-papers-by-year>
    <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-semibold text-chrome-800">{{ __('Papers by year') }}</h3>
        @if ($total > 0)
            <a href="{{ url('/app/rental/vehicle/' . $vehicleId . '/documents.zip') }}" data-papers-zip-all
               class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-chrome-700 ring-1 ring-chrome-200 hover:bg-chrome-50">
                <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 2.75a.75.75 0 0 0-1.5 0v8.614L6.295 8.235a.75.75 0 1 0-1.09 1.03l4.25 4.5a.75.75 0 0 0 1.09 0l4.25-4.5a.75.75 0 0 0-1.09-1.03l-2.955 3.129V2.75Z"/><path d="M3.5 12.75a.75.75 0 0 0-1.5 0v2.5A2.75 2.75 0 0 0 4.75 18h10.5A2.75 2.75 0 0 0 18 15.25v-2.5a.75.75 0 0 0-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5Z"/></svg>
                {{ __('Download all years (ZIP)') }}
            </a>
        @endif
    </div>
    <p class="mb-3 text-xs text-chrome-500">{{ __('Keep every year\'s registration, insurance and other papers here. Adding a new year does not replace an older one.') }}</p>

    {{-- Years --}}
    <div class="mb-3 flex flex-wrap items-center gap-1" dir="ltr" data-paper-years>
        @foreach ($years as $y)
            <button type="button" wire:click="showYear({{ $y }})"
                class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition {{ $y === $year ? 'bg-primary-400 text-chrome-900' : 'text-chrome-600 hover:bg-chrome-100' }}">
                {{ $y }}
                <span class="rounded-full px-1.5 text-[11px] font-bold tabular-nums {{ $y === $year ? 'bg-black/10 text-chrome-900' : 'bg-chrome-100 text-chrome-500' }}">{{ $counts[$y] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    {{-- The chosen year's papers --}}
    @if ($documents->isEmpty())
        <p class="rounded-lg bg-chrome-50 px-3 py-4 text-center text-sm text-chrome-500">{{ __('No papers saved for :year yet.', ['year' => $year]) }}</p>
    @else
        <ul class="divide-y divide-chrome-100 rounded-lg ring-1 ring-chrome-200">
            @foreach ($documents as $doc)
                <li wire:key="paper-{{ $doc->id }}" class="flex flex-wrap items-center gap-3 px-3 py-2 text-sm" data-paper="{{ $doc->id }}">
                    <span class="inline-flex shrink-0 rounded-full bg-primary-50 px-2 py-0.5 text-[11px] font-semibold text-primary-800">{{ $doc->kindLabel() }}</span>
                    <a href="{{ url('/app/rental/vehicle-document/' . $doc->id) }}" target="_blank" rel="noopener"
                       class="min-w-0 flex-1 truncate font-medium text-chrome-800 hover:underline">
                        {{ $doc->title ?: $doc->original_name }}
                    </a>
                    <span class="text-xs text-chrome-400">
                        {{ $doc->created_at?->format('d/m/Y') }}@if ($doc->uploaded_by) · {{ $doc->uploaded_by }}@endif
                    </span>
                    @if ($canDelete)
                        <button type="button" wire:click="delete({{ $doc->id }})"
                            wire:confirm="{{ __('Delete this paper for good? Download it first if you still need it.') }}"
                            class="rounded-md px-2 py-1 text-xs font-semibold text-red-600 hover:bg-red-50" title="{{ __('Delete') }}">
                            {{ __('Delete') }}
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>
        <a href="{{ url('/app/rental/vehicle/' . $vehicleId . '/documents.zip?year=' . $year) }}" data-papers-zip-year
           class="mt-2 inline-block text-xs font-semibold text-primary-700 hover:underline">
            {{ __('Download :year (ZIP)', ['year' => $year]) }}
        </a>
    @endif

    {{-- Add a paper --}}
    @if ($canAdd)
        <div class="mt-4 border-t border-chrome-100 pt-3"
             x-data="{
                busy: false, error: '', year: @js((string) $year), kind: 'registration', title: '',
                async add(e) {
                    const file = e.target.files[0];
                    if (!file) return;
                    this.busy = true; this.error = '';
                    const data = new FormData();
                    data.append('file', file); data.append('year', this.year); data.append('kind', this.kind); data.append('title', this.title);
                    try {
                        const r = await fetch(@js(url('/app/rental/vehicle/' . $vehicleId . '/documents')), {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                            body: data, credentials: 'same-origin',
                        });
                        if (!r.ok) {
                            const j = await r.json().catch(() => ({}));
                            this.error = (j.errors && Object.values(j.errors)[0][0]) || j.message || @js(__('Upload failed.'));
                            return;
                        }
                        this.title = '';
                        $wire.dispatch('vehicle-document-added', { year: parseInt(this.year, 10) });
                    } catch (err) {
                        this.error = err.message || @js(__('Upload failed.'));
                    } finally {
                        this.busy = false; e.target.value = '';
                    }
                },
             }">
            <div class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-chrome-400">{{ __('Add a paper') }}</div>
            <div class="grid gap-3 sm:grid-cols-4">
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-chrome-400">{{ __('Year') }}</label>
                    <select x-model="year" class="o-input w-full text-sm">
                        @foreach ($uploadYears as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-chrome-400">{{ __('Paper') }}</label>
                    <select x-model="kind" class="o-input w-full text-sm">
                        @foreach ($kinds as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-chrome-400">{{ __('Note (optional)') }}</label>
                    <input type="text" x-model="title" maxlength="120" class="o-input w-full text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-chrome-400">{{ __('File') }}</label>
                    <input type="file" accept=".pdf,image/*" @change="add($event)" :disabled="busy" class="block w-full text-xs">
                </div>
            </div>
            <p class="mt-2 text-[11px] text-chrome-400">{{ __('Choose the year and the paper first, then the file. It is saved as soon as it is chosen. PDF or photo, up to 8 MB.') }}</p>
            <p x-show="busy" class="mt-1 text-xs text-chrome-500">{{ __('Uploading…') }}</p>
            <p x-show="error" x-text="error" class="mt-1 text-xs text-red-600"></p>
        </div>
    @endif
</div>
