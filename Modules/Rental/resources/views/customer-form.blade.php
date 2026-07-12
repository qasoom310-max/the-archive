@php use App\Erp\Views\ValueFormat; @endphp
@php use Modules\Rental\Models\RentalCustomer; @endphp
@php $lbl = 'mb-1.5 block text-xs font-medium uppercase tracking-wide text-chrome-500'; @endphp
<div class="mx-auto max-w-5xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Customers')" :parent-url="url('/app/rental/customer')" :current="$name ?: __('New customer')" />

    {{-- ───────── Details ───────── --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-6">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-sm font-semibold text-chrome-800">{{ $isEditing ? __('Edit customer') : __('New customer') }}</h2>
            {{-- Individual / Company — chosen at creation, then locked (super-admin can change). --}}
            @if ($canChangeType)
                <div class="inline-flex rounded-lg bg-chrome-100 p-0.5 text-sm">
                    @foreach ($typeOptions as $opt)
                        <button type="button" wire:click="$set('type', '{{ $opt['value'] }}')"
                            class="rounded-md px-3 py-1 font-medium transition {{ $type === $opt['value'] ? 'bg-white text-chrome-900 shadow-sm' : 'text-chrome-500 hover:text-chrome-700' }}">
                            {{ __($opt['label']) }}
                        </button>
                    @endforeach
                </div>
            @else
                <span class="inline-flex items-center gap-1.5 rounded-lg bg-chrome-100 px-3 py-1 text-sm font-medium text-chrome-600">
                    <svg class="size-3.5 text-chrome-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z" clip-rule="evenodd"/></svg>
                    {{ $type === 'company' ? __('Company') : __('Individual') }}
                </span>
            @endif
        </div>

        @php $dial = collect($countries)->firstWhere('code', $country)['dial'] ?? ''; @endphp
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="{{ $lbl }}">{{ $type === 'company' ? __('Company name') : __('Name') }} <span class="text-red-500">*</span></label>
                <input type="text" wire:model="name" class="o-input w-full" placeholder="{{ $type === 'company' ? __('e.g. Wanaan Trading W.L.L.') : __('Full name') }}">
                @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="{{ $lbl }}">{{ __('Country') }}</label>
                <select wire:model.live="country" class="o-input w-full">
                    @foreach ($countries as $c)
                        <option value="{{ $c['code'] }}">{{ RentalCustomer::flagFor($c['code']) }} {{ $c['name'] }} ({{ $c['dial'] }})</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="{{ $lbl }}">{{ $type === 'company' ? __('Company phone') : __('Phone') }}</label>
                <div class="flex">
                    <span class="inline-flex items-center rounded-s-md border border-e-0 border-chrome-300 bg-chrome-50 px-3 text-sm text-chrome-500">{{ $dial ?: '+' }}</span>
                    <input type="tel" wire:model="phone" class="o-input w-full rounded-s-none" placeholder="{{ __('Local number') }}">
                </div>
                @error('phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="{{ $lbl }}">{{ __('Email') }}</label>
                <input type="email" wire:model="email" class="o-input w-full">
                @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            @if ($type === 'company')
                {{-- ── Company fields ── --}}
                <div>
                    <label class="{{ $lbl }}">{{ __('CR number') }}</label>
                    <input type="text" wire:model="cr_number" class="o-input w-full">
                    @error('cr_number') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div x-data="{
                         busy: false, error: '', done: false,
                         async upload(e) {
                             const file = e.target.files[0]; if (!file) return;
                             this.busy = true; this.error = ''; this.done = false;
                             const data = new FormData(); data.append('file', file); data.append('bucket', 'rental_customers'); data.append('only', 'pdf');
                             try {
                                 const r = await fetch(@js(route('form.upload-file')), { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }, body: data, credentials: 'same-origin' });
                                 if (!r.ok) { const j = await r.json().catch(() => ({})); this.error = (j.errors && j.errors.file && j.errors.file[0]) || j.message || @js(__('Upload failed.')); return; }
                                 const j = await r.json(); await $wire.set('crDocumentPath', j.path); this.done = true;
                             } catch (err) { this.error = err.message || @js(__('Upload failed.')); } finally { this.busy = false; }
                         },
                     }">
                    <label class="{{ $lbl }}">{{ __('CR document (PDF)') }}</label>
                    <input type="file" accept="application/pdf" @change="upload($event)"
                        class="block w-full text-sm text-chrome-600 file:mr-3 file:rounded-lg file:border-0 file:bg-chrome-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-chrome-700">
                    <p x-show="busy" class="mt-1 text-xs text-chrome-400">{{ __('Uploading…') }}</p>
                    <p x-show="done" class="mt-1 text-xs text-emerald-600">{{ __('Uploaded — save to attach.') }}</p>
                    <p x-show="error" x-text="error" class="mt-1 text-xs text-red-600"></p>
                    @if ($existingCrDocument)
                        <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($existingCrDocument) }}" target="_blank" rel="noopener" class="mt-1 inline-block text-xs font-medium text-primary-700 hover:underline">{{ __('View current CR') }} ↗</a>
                    @endif
                </div>
                <div>
                    <label class="{{ $lbl }}">{{ __('Contact person') }}</label>
                    <input type="text" wire:model="contact_person" class="o-input w-full" placeholder="{{ __('Who we deal with') }}">
                </div>
                <div>
                    <label class="{{ $lbl }}">{{ __('Contact person phone') }}</label>
                    <input type="tel" wire:model="contact_phone" class="o-input w-full">
                </div>
            @else
                {{-- ── Individual fields ── --}}
                <div>
                    <label class="{{ $lbl }}">{{ __('CPR / ID') }}</label>
                    <input type="text" wire:model="cpr" class="o-input w-full">
                </div>
                <div>
                    <label class="{{ $lbl }}">{{ __('Licence no.') }}</label>
                    <input type="text" wire:model="license_no" class="o-input w-full">
                </div>
                <div>
                    <label class="{{ $lbl }}">{{ __('Nationality') }}</label>
                    <input type="text" wire:model="nationality" class="o-input w-full">
                </div>
            @endif

            <div class="sm:col-span-2">
                <label class="{{ $lbl }}">{{ __('Address') }}</label>
                <textarea wire:model="address" rows="2" class="o-input w-full"></textarea>
            </div>

            <label class="inline-flex items-center gap-2 text-sm text-chrome-700">
                <input type="checkbox" wire:model="active" class="rounded border-chrome-300 text-primary-600">
                {{ __('Active') }}
            </label>
        </div>

        <div class="mt-5 flex justify-end">
            <button wire:click="save" class="o-btn-primary">
                <span wire:loading.remove wire:target="save">{{ $isEditing ? __('Save') : __('Create customer') }}</span>
                <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
            </button>
        </div>
    </div>

    @if ($isEditing)
        {{-- ───────── At a glance ───────── --}}
        <div class="mt-6 grid gap-4 sm:grid-cols-3">
            @php
                $cards = [
                    ['label' => __('Rentals'), 'value' => $stats['rentals'], 'tint' => 'bg-primary-100 text-primary-700 ring-primary-400/25', 'icon' => '<path d="M3 9.5 4.2 6.6A2 2 0 0 1 6 5.5h8a2 2 0 0 1 1.8 1.1L17 9.5a2 2 0 0 1 1 1.7V13a1 1 0 0 1-1 1h-1a2 2 0 1 1-4 0H8a2 2 0 1 1-4 0H3a1 1 0 0 1-1-1v-1.8a2 2 0 0 1 1-1.7Z"/><circle cx="6.5" cy="14" r="1.5"/><circle cx="13.5" cy="14" r="1.5"/>'],
                    ['label' => __('Limousine trips'), 'value' => $stats['limo'], 'tint' => 'bg-indigo-50 text-indigo-600 ring-indigo-100', 'icon' => '<path fill-rule="evenodd" d="M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2ZM3.5 8.5v6.75c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25V8.5h-13Z" clip-rule="evenodd"/>'],
                ];
            @endphp
            @foreach ($cards as $cc)
                <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
                    <span class="flex size-9 items-center justify-center rounded-xl ring-1 {{ $cc['tint'] }}">
                        <svg class="size-5" viewBox="0 0 20 20" fill="currentColor">{!! $cc['icon'] !!}</svg>
                    </span>
                    <div class="mt-3 text-3xl font-bold tracking-tight text-chrome-900">{{ $cc['value'] }}</div>
                    <div class="text-sm font-medium text-chrome-500">{{ $cc['label'] }}</div>
                </div>
            @endforeach
            <div class="rounded-2xl bg-gradient-to-br from-emerald-600 to-emerald-700 p-5 shadow-sm">
                <span class="flex size-9 items-center justify-center rounded-xl bg-white/15 text-white ring-1 ring-white/20">
                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor"><path d="M1 4.25C1 3.56 1.56 3 2.25 3h15.5c.69 0 1.25.56 1.25 1.25v8.5c0 .69-.56 1.25-1.25 1.25H2.25C1.56 14 1 13.44 1 12.75v-8.5ZM10 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/></svg>
                </span>
                <div class="mt-3 text-2xl font-bold tracking-tight text-white">{{ ValueFormat::money($stats['spend']) }}</div>
                <div class="text-sm font-medium text-white/70">{{ __('Total spend') }}</div>
            </div>
        </div>

        {{-- ───────── Documents ───────── --}}
        @if (! empty($documents))
            <div class="mb-3 mt-8 flex items-center gap-2">
                <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('Documents') }}</h2>
                <span class="rounded-full bg-chrome-100 px-2 py-0.5 text-[11px] font-bold text-chrome-500">{{ count($documents) }}</span>
                <span class="h-px flex-1 bg-chrome-200"></span>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($documents as $d)
                    <div class="group relative overflow-hidden rounded-xl bg-white ring-1 ring-chrome-900/[0.06]">
                        <a href="{{ $d['url'] }}" target="_blank" rel="noopener" class="block">
                            @if ($d['kind'] === 'image')
                                <img src="{{ $d['url'] }}" alt="{{ $d['label'] }}" class="h-28 w-full bg-chrome-50 object-cover">
                            @else
                                <div class="flex h-28 w-full items-center justify-center bg-chrome-50">
                                    <svg class="size-10 text-red-500" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 0 1 2-2h5.586A2 2 0 0 1 13 2.586L15.414 5A2 2 0 0 1 16 6.414V16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4Zm7 0v3a1 1 0 0 0 1 1h3l-4-4Z" clip-rule="evenodd"/></svg>
                                </div>
                            @endif
                        </a>
                        <div class="flex items-center justify-between gap-2 px-3 py-2">
                            <div class="min-w-0">
                                <div class="truncate text-xs font-semibold text-chrome-700">{{ $d['label'] }}</div>
                                @if ($d['ref'])<div class="truncate text-[11px] text-chrome-400">{{ $d['ref'] }}</div>@endif
                            </div>
                            <a href="{{ $d['url'] }}" download class="shrink-0 rounded-md bg-chrome-100 p-1.5 text-chrome-600 hover:bg-chrome-200" title="{{ __('Download') }}" aria-label="{{ __('Download') }}">
                                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 2.75a.75.75 0 0 0-1.5 0v8.614L6.295 8.235a.75.75 0 1 0-1.09 1.03l4.25 4.5a.75.75 0 0 0 1.09 0l4.25-4.5a.75.75 0 0 0-1.09-1.03l-2.955 3.129V2.75Z"/><path d="M3.5 12.75a.75.75 0 0 0-1.5 0v2.5A2.75 2.75 0 0 0 4.75 18h10.5A2.75 2.75 0 0 0 18 15.25v-2.5a.75.75 0 0 0-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5Z"/></svg>
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ───────── Rental orders ───────── --}}
        <div class="mb-3 mt-8 flex items-center gap-2">
            <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('Rental orders') }}</h2>
            <span class="h-px flex-1 bg-chrome-200"></span>
        </div>
        @if ($orders->isNotEmpty())
            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
                <table class="w-full min-w-[640px] divide-y divide-chrome-100 text-sm">
                    <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                        <tr>
                            <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Car') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Pick-up') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('Total') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Payment') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-chrome-50">
                        @foreach ($orders as $o)
                            @php $sb = ['draft' => 'bg-chrome-200 text-chrome-700', 'active' => 'bg-sky-100 text-sky-700', 'closed' => 'bg-emerald-100 text-emerald-700', 'cancelled' => 'bg-red-100 text-red-700'][$o->state] ?? 'bg-chrome-200 text-chrome-700'; @endphp
                            <tr class="cursor-pointer hover:bg-chrome-50" onclick="window.location='{{ url('/app/rental/order/' . $o->id) }}'">
                                <td class="px-4 py-2 font-medium text-chrome-800">{{ $o->reference }}</td>
                                <td class="px-4 py-2 text-chrome-700">{{ $o->vehicle?->displayName() ?? '—' }}</td>
                                <td class="px-4 py-2 text-chrome-600">{{ $o->start_date?->isoFormat('MMM D, YYYY') ?? '—' }}</td>
                                <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ ValueFormat::money($o->total) }}</td>
                                <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $sb }}">{{ __(ucfirst($o->state)) }}</span></td>
                                <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $o->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ __(ucfirst($o->payment_status)) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="rounded-2xl bg-white px-4 py-8 text-center text-sm text-chrome-400 shadow-sm ring-1 ring-chrome-900/[0.06]">{{ __('No rental orders yet.') }}</p>
        @endif

        {{-- ───────── Limousine bookings ───────── --}}
        @if ($limoBookings->isNotEmpty())
            <div class="mb-3 mt-8 flex items-center gap-2">
                <h2 class="text-xs font-bold uppercase tracking-wider text-chrome-500">{{ __('Limousine bookings') }}</h2>
                <span class="h-px flex-1 bg-chrome-200"></span>
            </div>
            <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
                <table class="w-full min-w-[640px] divide-y divide-chrome-100 text-sm">
                    <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                        <tr>
                            <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Pick-up') }}</th>
                            <th class="px-4 py-2 text-end">{{ __('Fare') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                            <th class="px-4 py-2 text-start">{{ __('Payment') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-chrome-50">
                        @foreach ($limoBookings as $b)
                            @php $lb = ['queue' => 'bg-amber-100 text-amber-700', 'confirmed' => 'bg-sky-100 text-sky-700', 'active' => 'bg-indigo-100 text-indigo-700', 'completed' => 'bg-emerald-100 text-emerald-700', 'cancelled' => 'bg-red-100 text-red-700'][$b->status] ?? 'bg-chrome-200 text-chrome-700'; @endphp
                            <tr class="cursor-pointer hover:bg-chrome-50" onclick="window.location='{{ url('/app/limousine/booking/' . $b->id) }}'">
                                <td class="px-4 py-2 font-medium text-chrome-800">{{ $b->reference }}</td>
                                <td class="px-4 py-2 text-chrome-600">{{ $b->pickup_at ? \Illuminate\Support\Carbon::parse($b->pickup_at)->isoFormat('MMM D, h:mm A') : '—' }}</td>
                                <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ ValueFormat::money((float) $b->fare) }}</td>
                                <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $lb }}">{{ __(ucfirst($b->status)) }}</span></td>
                                <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $b->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ __(ucfirst($b->payment_status)) }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Audit trail: who created / edited this customer. --}}
        <div class="mt-8">
            <x-activity-trail :subject="$customer" />
        </div>
    @endif
</div>
