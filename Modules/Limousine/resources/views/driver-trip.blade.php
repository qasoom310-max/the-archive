{{-- The driver's trip page, from the link the office copies to him.
     Standalone (no app chrome, no login, no Alpine): a driver on a phone,
     usually inside WhatsApp. The ACTION comes first (the owner's call): the
     photo / Start / End card sits right under the header, the trip details
     after it. Every label is English with the Arabic under it. --}}
<!DOCTYPE html>
<html lang="en" dir="ltr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#18181b">
    <title>Wanaan · Trip</title>
    @vite(['resources/css/app.css'])
    <style>
        .ar { display: block; unicode-bidi: plaintext; text-align: start; font-size: .85em; opacity: .75; }
        .tap { -webkit-tap-highlight-color: transparent; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .spin { animation: spin 1s linear infinite; }
        .fuel-range { -webkit-appearance: none; appearance: none; height: 44px; border-radius: 9999px; background: #e5e7eb; outline: none; touch-action: pan-y; box-shadow: inset 0 0 0 2px rgba(0,0,0,.08); }
        .fuel-range::-webkit-slider-thumb { -webkit-appearance: none; width: 40px; height: 40px; border-radius: 9999px; background: #fff; border: 3px solid #18181b; box-shadow: 0 2px 6px rgba(0,0,0,.25); cursor: pointer; }
        .fuel-range::-moz-range-thumb { width: 36px; height: 36px; border-radius: 9999px; background: #fff; border: 3px solid #18181b; cursor: pointer; }
    </style>
</head>
<body class="min-h-full bg-chrome-100 font-sans antialiased">
@php
    // English line with its Arabic underneath, the one pattern used everywhere here.
    $bi = static fn (string $en, string $ar): \Illuminate\Support\HtmlString => new \Illuminate\Support\HtmlString(e($en) . '<span class="ar" lang="ar">' . e($ar) . '</span>');
@endphp

@if ($invalid)
    <div class="mx-auto max-w-md p-4">
        <div class="mt-10 rounded-3xl bg-white p-8 text-center shadow-sm" data-trip-invalid>
            <div class="mx-auto flex size-14 items-center justify-center rounded-full bg-red-100 text-2xl">⛔</div>
            <h1 class="mt-4 text-lg font-bold text-chrome-900">{{ $bi('This link no longer works', 'هذا الرابط لم يعد يعمل') }}</h1>
            <p class="mt-2 text-sm text-chrome-600">{{ $bi('The trip was given to another driver or cancelled. Call the office.', 'أُعطيت الرحلة لسائق آخر أو أُلغيت. اتصل بالمكتب.') }}</p>
        </div>
    </div>
@else
    @php
        $started = $run?->isStarted() ?? false;
        $ended = $run?->isEnded() ?? false;
        $stage = $started ? 'end' : 'start';
        $read = $run?->getAttribute($stage . '_read');
        $hasPhoto = ($run?->getAttribute($stage . '_photo') ?? null) !== null;
        $fuelNow = (int) ($read['fuel_eighths'] ?? 4);
        $status = $ended ? ['Finished', 'انتهت', 'bg-emerald-400 text-emerald-950']
            : ($started ? ['On the trip', 'في الرحلة', 'bg-sky-300 text-sky-950'] : ['Not started', 'لم تبدأ', 'bg-primary-400 text-chrome-900']);
    @endphp

    {{-- Header: what and when, at a glance. --}}
    <header class="bg-chrome-900 px-5 pb-10 pt-6 text-white">
        <div class="mx-auto max-w-md">
            <div class="flex items-center justify-between">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-primary-400">Wanaan</p>
                <span class="rounded-full px-3 py-1 text-xs font-bold {{ $status[2] }}">{{ $status[0] }} · {{ $status[1] }}</span>
            </div>
            <h1 class="mt-3 text-3xl font-black tracking-tight">Ref. # {{ $trip->reference }}</h1>
            @if ($trip->start_at)
                <p class="mt-1 text-lg font-semibold text-white/90">{{ $trip->start_at->isoFormat('ddd D MMM') }} · <span class="text-primary-400">{{ $trip->start_at->isoFormat('HH:mm') }}</span></p>
            @endif
            <p class="mt-2 text-sm text-white/60">{{ str_replace(':driver', (string) $trip->driver, \Modules\Limousine\Support\DriverTripLink::THANKS_EN) }}</p>
        </div>
    </header>

    <main class="mx-auto -mt-6 max-w-md space-y-4 px-4 pb-10">

    @if ($blocking !== null)
        <section class="rounded-3xl bg-white p-5 shadow-lg ring-2 ring-red-300" data-trip-blocked>
            <div class="flex items-start gap-3">
                <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-red-100 text-xl">✋</div>
                <div>
                    <p class="font-bold text-red-800">{{ $bi('Finish your other trip first', 'أنهِ رحلتك الأخرى أولاً') }}</p>
                    <p class="mt-1 text-sm text-chrome-600">{{ $bi('Ref. # ' . $blocking->leg?->reference . ' is still open. Press End trip on it, then come back.', 'الرحلة ' . $blocking->leg?->reference . ' ما زالت مفتوحة. اضغط «إنهاء الرحلة» فيها ثم ارجع.') }}</p>
                </div>
            </div>
            @if ($blockingUrl)
                <a href="{{ $blockingUrl }}" class="tap mt-4 flex h-14 items-center justify-center rounded-2xl bg-chrome-900 text-base font-bold text-white">{{ $bi('Open that trip', 'افتح تلك الرحلة') }}</a>
            @endif
        </section>
    @elseif ($ended)
        <section class="rounded-3xl bg-white p-6 text-center shadow-lg" data-trip-ended>
            <div class="mx-auto flex size-16 items-center justify-center rounded-full bg-emerald-100 text-3xl">✅</div>
            <p class="mt-3 text-xl font-black text-chrome-900">{{ $bi('Trip finished. Thank you!', 'انتهت الرحلة. شكراً لك!') }}</p>
            <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                <div class="rounded-2xl bg-chrome-50 p-3"><p class="text-[11px] font-semibold uppercase text-chrome-500">Time</p><p class="mt-1 font-bold tabular-nums">{{ $run->started_at?->isoFormat('HH:mm') }}–{{ $run->ended_at?->isoFormat('HH:mm') }}</p></div>
                <div class="rounded-2xl bg-chrome-50 p-3"><p class="text-[11px] font-semibold uppercase text-chrome-500">KM</p><p class="mt-1 font-bold tabular-nums">{{ $run->distance() !== null ? number_format((int) $run->distance()) : '—' }}</p></div>
                <div class="rounded-2xl bg-chrome-50 p-3"><p class="text-[11px] font-semibold uppercase text-chrome-500">Fuel</p><p class="mt-1 font-bold tabular-nums">{{ $run->end_fuel !== null ? $run->end_fuel . '/8' : '—' }}</p></div>
            </div>
        </section>
    @else
        {{-- THE ACTION: photo → check → Start / End. --}}
        <section class="rounded-3xl bg-white p-5 shadow-lg" data-trip-step="{{ $stage }}">
            @if ($started)
                <div class="mb-4 flex items-center gap-3 rounded-2xl bg-sky-50 p-3 text-sky-900">
                    <span class="flex size-9 items-center justify-center rounded-full bg-sky-200 text-lg">🚗</span>
                    <p class="text-sm font-semibold">{{ $bi('On the trip since ' . $run->started_at?->isoFormat('HH:mm') . ' · ' . number_format((int) $run->start_km) . ' km', 'في الرحلة منذ ' . $run->started_at?->isoFormat('HH:mm')) }}</p>
                </div>
                <h2 class="text-xl font-black text-chrome-900">{{ $bi('End your trip', 'أنهِ رحلتك') }}</h2>
            @else
                <h2 class="text-xl font-black text-chrome-900">{{ $bi('Start your trip', 'ابدأ رحلتك') }}</h2>
            @endif

            {{-- Three steps, so he always knows where he is. --}}
            {{-- A real progress line: a circle per step, green with a tick when done. --}}
            <ol class="mt-5 flex items-start" id="steps">
                @foreach ([['Photo', 'الصورة'], ['Check', 'التحقق'], [$started ? 'End' : 'Start', $started ? 'الإنهاء' : 'البدء']] as $n => [$en, $ar])
                    <li data-step="{{ $n + 1 }}" class="relative flex flex-1 flex-col items-center text-center">
                        @if ($n > 0)
                            <span data-step-line class="absolute end-1/2 top-[18px] h-1 w-full -translate-y-1/2 rounded-full bg-chrome-200"></span>
                        @endif
                        <span data-step-dot class="relative z-10 flex size-9 items-center justify-center rounded-full bg-chrome-200 text-sm font-black text-chrome-500 ring-4 ring-white">{{ $n + 1 }}</span>
                        <span data-step-label class="mt-1.5 text-xs font-bold text-chrome-400">{{ $en }}<span class="ar">{{ $ar }}</span></span>
                    </li>
                @endforeach
            </ol>

            <label class="tap mt-4 flex cursor-pointer items-center gap-4 rounded-2xl border-2 border-dashed {{ $hasPhoto ? 'border-chrome-200 bg-chrome-50' : 'border-primary-500 bg-primary-400/15' }} p-4" data-photo-pick>
                <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl {{ $hasPhoto ? 'bg-chrome-200' : 'bg-primary-400' }}">
                    <svg class="size-7 text-chrome-900" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z"/></svg>
                </span>
                <span class="min-w-0">
                    <span id="photoLabel" class="block font-bold text-chrome-900">{{ $hasPhoto ? $bi('Take the photo again', 'أعد التصوير') : $bi('Photo of the dashboard', 'صوّر لوحة العدادات') }}</span>
                    <span class="mt-0.5 block text-xs text-chrome-500">{{ $bi('KM and fuel must be clear', 'يجب أن يظهر العداد والوقود بوضوح') }}</span>
                </span>
                <input type="file" accept="image/*" capture="environment" class="hidden" id="photo">
            </label>
            <img id="preview" alt="" class="mt-3 hidden max-h-48 w-full rounded-2xl object-cover">

            <div id="reading" hidden><div class="mt-4 flex items-center gap-3 rounded-2xl bg-chrome-50 p-4 text-sm font-semibold text-chrome-700">
                <svg class="spin size-5 text-chrome-500" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                <span>{{ $bi('Reading your dashboard…', 'جارٍ قراءة لوحة العدادات…') }}</span>
            </div></div>

            <div id="confirm" class="mt-5 space-y-5" @if (! $hasPhoto) hidden @endif>
                <p id="readNote" class="rounded-xl bg-amber-50 px-3 py-2 text-xs font-medium text-amber-900">{{ $bi('Check the numbers. Correct them if wrong.', 'تحقق من الأرقام وصححها إن كانت خاطئة.') }}</p>

                <label class="block">
                    <span class="text-sm font-bold text-chrome-800">KM <span class="font-medium text-chrome-500">· عداد الكيلومترات</span></span>
                    <input type="number" inputmode="numeric" min="1" id="km" class="o-input mt-2 h-16 w-full rounded-2xl text-center text-3xl font-black tabular-nums tracking-wider" value="{{ ($read['km'] ?? 0) > 0 ? $read['km'] : '' }}" placeholder="000000">
                </label>

                {{-- A fuel tank of 8 bars (the owner's call): filled from the photo, tap to correct, E = empty. --}}
                {{-- Fuel as a settings-style slider (the owner's call): set from the photo,
                     drag or tap to correct. Eighths of a tank, 0 = E, 8 = F. --}}
                <div data-fuel-tank>
                    <div class="flex items-center justify-between">
                        <span class="flex items-center gap-2 text-sm font-bold text-chrome-800">
                            <span class="flex size-8 items-center justify-center rounded-xl bg-amber-100 text-base">⛽</span>
                            Fuel <span class="font-medium text-chrome-500">· الوقود</span>
                        </span>
                        <span class="text-end"><span id="fuelWord" class="block text-sm font-black text-chrome-900"></span><span class="text-[11px] text-chrome-500"><span id="fuelNum">{{ $fuelNow }}</span>/8</span></span>
                    </div>
                    <input type="hidden" id="fuel" value="{{ $fuelNow }}">
                    <input type="range" id="fuelRange" min="0" max="8" step="1" value="{{ $fuelNow }}" class="fuel-range mt-4 w-full" aria-label="Fuel">
                    <div class="mt-1 flex justify-between px-1 text-xs font-bold text-chrome-500">
                        <span class="text-red-600">E</span><span>¼</span><span>½</span><span>¾</span><span class="text-emerald-700">F</span>
                    </div>
                    <p class="mt-1 text-center text-[11px] text-chrome-400">slide or tap · اسحب أو اضغط</p>
                </div>
            </div>

                {{-- The big button, right under the numbers it saves. --}}
                <div id="goBar" class="mt-6" @if (! $hasPhoto) hidden @endif>
                <button type="button" id="go" data-trip-go="{{ $stage }}"
                        class="tap flex h-16 w-full items-center justify-center gap-3 rounded-2xl text-lg font-black text-white shadow-xl {{ $started ? 'bg-red-600 active:bg-red-700' : 'bg-emerald-600 active:bg-emerald-700' }} disabled:opacity-60">
                    <span id="goLabel">{{ $started ? 'End trip · إنهاء الرحلة' : 'Start trip · بدء الرحلة' }}</span>
                </button>
                <p class="mt-1.5 text-center text-[11px] text-chrome-500">📍 Your location is saved when you press · يُحفظ موقعك عند الضغط</p>
            </div>

            <p id="error" hidden class="mt-4 rounded-xl bg-red-50 px-3 py-2 text-sm font-semibold text-red-700" role="alert"></p>
        </section>

    @endif

    {{-- The trip itself, after the action. --}}
    <section class="rounded-3xl bg-white p-5 shadow-sm" data-trip-details>
        <h2 class="text-sm font-bold uppercase tracking-wide text-chrome-500">Trip details · تفاصيل الرحلة</h2>
        <dl class="mt-3 space-y-3">
            @foreach ($details as $row)
                @if (isset($row['locked']))
                    <div class="flex items-center gap-3 rounded-2xl bg-amber-50 p-3 ring-1 ring-amber-200" data-address-locked>
                        <span class="text-2xl">🔒</span>
                        <div>
                            <dt class="text-xs font-semibold text-amber-800">{{ $row['label'] }}</dt>
                            <dd class="text-sm font-semibold text-amber-900">{{ $row['value'] }}</dd>
                        </div>
                    </div>
                @else
                    <div class="flex items-start justify-between gap-3 border-b border-chrome-100 pb-3 last:border-0 last:pb-0">
                        <div class="min-w-0">
                            <dt class="text-xs text-chrome-500">{{ $row['label'] }}</dt>
                            <dd class="whitespace-pre-line font-semibold text-chrome-900">{{ $row['value'] }}</dd>
                        </div>
                        @if (isset($row['url']))
                            <a href="{{ $row['url'] }}" target="_blank" rel="noopener"
                               class="tap shrink-0 rounded-xl px-3 py-2 text-xs font-bold {{ str_starts_with($row['url'], 'tel:') ? 'bg-emerald-600 text-white' : 'bg-chrome-900 text-white' }}">
                                {{ str_starts_with($row['url'], 'tel:') ? '📞 Call' : '🧭 Map' }}
                            </a>
                        @endif
                    </div>
                @endif
            @endforeach
        </dl>
    </section>

    </main>

    @if (! $ended && $blocking === null)
    <script>
        (function () {
            var url = @js($postUrl), stage = @js($stage), hasPhoto = @js($hasPhoto);
            var token = document.querySelector('meta[name="csrf-token"]').content;
            var $ = function (id) { return document.getElementById(id); };

            function steps(n) {
                document.querySelectorAll('#steps [data-step]').forEach(function (li) {
                    var k = parseInt(li.getAttribute('data-step'), 10);
                    var dot = li.querySelector('[data-step-dot]'), label = li.querySelector('[data-step-label]'), line = li.querySelector('[data-step-line]');
                    var done = k < n, now = k === n;
                    dot.className = 'relative z-10 flex size-9 items-center justify-center rounded-full text-sm font-black ring-4 ring-white '
                        + (done ? 'bg-emerald-500 text-white' : (now ? 'bg-chrome-900 text-white' : 'bg-chrome-200 text-chrome-500'));
                    dot.textContent = done ? '✓' : String(k);
                    label.className = 'mt-1.5 text-xs font-bold ' + (done ? 'text-emerald-700' : (now ? 'text-chrome-900' : 'text-chrome-400'));
                    if (line) { line.className = 'absolute end-1/2 top-[18px] h-1 w-full -translate-y-1/2 rounded-full ' + (k <= n ? 'bg-emerald-500' : 'bg-chrome-200'); }
                });
            }
            steps(hasPhoto ? 2 : 1);

            var words = ['Empty · فارغ', 'Almost empty · شبه فارغ', 'Quarter · ربع', 'Less than half · أقل من النصف', 'Half · نصف', 'More than half · أكثر من النصف', 'Three quarters · ثلاثة أرباع', 'Almost full · شبه ممتلئ', 'Full · ممتلئ'];
            function setFuel(n) {
                n = Math.max(0, Math.min(8, parseInt(n, 10) || 0));
                $('fuel').value = String(n); $('fuelRange').value = String(n);
                $('fuelNum').textContent = n;
                $('fuelWord').textContent = words[n];
                var colour = n <= 2 ? '#ef4444' : (n <= 5 ? '#f59e0b' : '#10b981'), pct = n / 8 * 100;
                $('fuelRange').style.background = 'linear-gradient(to right, ' + colour + ' ' + pct + '%, #e5e7eb ' + pct + '%)';
            }
            $('fuelRange').addEventListener('input', function () { setFuel(this.value); });
            setFuel($('fuel').value);

            function fail(msg) {
                $('reading').hidden = true; $('go').disabled = false;
                $('error').textContent = msg || 'Something went wrong. Try again. · حدث خطأ، حاول مرة أخرى.';
                $('error').hidden = false;
            }
            function post(body) {
                body.append('_token', token);
                return fetch(url, { method: 'POST', body: body, headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token } })
                    .then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw new Error(j.message || ''); } return j; }); });
            }

            $('photo').addEventListener('change', function (e) {
                var file = e.target.files[0]; if (!file) { return; }
                $('error').hidden = true;
                $('preview').src = URL.createObjectURL(file); $('preview').classList.remove('hidden');
                $('reading').hidden = false;
                var fd = new FormData(); fd.append('action', 'photo'); fd.append('file', file);
                post(fd).then(function (j) {
                    $('reading').hidden = true;
                    $('confirm').hidden = false; $('goBar').hidden = false;
                    $('photoLabel').textContent = 'Take the photo again · أعد التصوير';
                    if (j.readable && j.km > 0) { $('km').value = j.km; }
                    else { $('km').value = ''; $('readNote').textContent = 'The KM could not be read. Type it. · لم تتم قراءة العداد، اكتبه.'; }
                    if (j.fuel_readable) { setFuel(j.fuel_eighths); }
                    steps(2);
                    $('confirm').scrollIntoView({ behavior: 'smooth', block: 'start' });
                }).catch(function (err) { fail(err.message); });
                e.target.value = '';
            });

            function send(pos) {
                var fd = new FormData();
                fd.append('action', stage); fd.append('km', $('km').value); fd.append('fuel', $('fuel').value);
                if (pos) { fd.append('lat', pos.coords.latitude); fd.append('lng', pos.coords.longitude); fd.append('accuracy', pos.coords.accuracy); }
                post(fd).then(function () { location.reload(); }).catch(function (err) { fail(err.message); });
            }
            $('go').addEventListener('click', function () {
                if (!$('km').value) { fail('Type the KM. · اكتب عدد الكيلومترات.'); $('km').focus(); return; }
                $('error').hidden = true; $('go').disabled = true; steps(3);
                $('goLabel').textContent = 'Please wait… · يرجى الانتظار…';
                if (!navigator.geolocation) { send(null); return; }
                navigator.geolocation.getCurrentPosition(send, function () {
                    if (confirm('Your location could not be read. Allow location and try again, or press OK to continue without it (the office will see that).\n\nتعذر قراءة موقعك. اسمح بالموقع وحاول مرة أخرى، أو اضغط موافق للمتابعة بدونه (سيرى المكتب ذلك).')) { send(null); }
                    else { $('go').disabled = false; $('goLabel').textContent = @js($started ? 'End trip · إنهاء الرحلة' : 'Start trip · بدء الرحلة'); }
                }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
            });
        })();
    </script>
    @endif
@endif
</body>
</html>
