{{-- The driver's trip page, from the link the office copies to him.
     Standalone (no app chrome, no login, no Alpine): a driver on a phone,
     usually inside WhatsApp. Every label is English AND Arabic at once. --}}
<!DOCTYPE html>
<html lang="en" dir="ltr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>Wanaan · Trip</title>
    @vite(['resources/css/app.css'])
    <style>.ar{direction:rtl;font-size:.92em}</style>
</head>
<body class="min-h-full bg-chrome-100 font-sans">
<div class="mx-auto max-w-md space-y-4 p-4">
@if ($invalid)
    <div class="rounded-2xl bg-white p-6 text-center shadow-sm ring-1 ring-chrome-900/5" data-trip-invalid>
        <h1 class="text-lg font-bold text-chrome-900">This link no longer works</h1>
        <p class="ar mt-1 font-bold text-chrome-900">هذا الرابط لم يعد يعمل</p>
        <p class="mt-2 text-sm text-chrome-600">The trip was given to another driver or cancelled. Call the office.</p>
        <p class="ar text-sm text-chrome-600">أُعطيت الرحلة لسائق آخر أو أُلغيت. اتصل بالمكتب.</p>
    </div>
@else
    @php
        $started = $run?->isStarted() ?? false;
        $ended = $run?->isEnded() ?? false;
        $stage = $started ? 'end' : 'start';
        $read = $run?->getAttribute($stage . '_read');
        $hasPhoto = ($run?->getAttribute($stage . '_photo') ?? null) !== null;
        $fuels = ['Empty / فارغ', '1/8', '1/4', '3/8', '1/2', '5/8', '3/4', '7/8', 'Full / ممتلئ'];
    @endphp

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
        <p class="text-xs font-semibold uppercase tracking-wide text-chrome-500">Wanaan · ونان</p>
        <h1 class="mt-1 text-lg font-bold text-chrome-900">Booking No {{ $trip->reference }}</h1>
        <p class="ar font-bold text-chrome-900">رقم الحجز {{ $trip->reference }}</p>
        <p class="mt-2 text-sm text-chrome-600">{{ str_replace(':driver', (string) $trip->driver, \Modules\Limousine\Support\DriverTripLink::THANKS_EN) }}</p>
        <p class="ar text-sm text-chrome-600">{{ str_replace(':driver', (string) $trip->driver, \Modules\Limousine\Support\DriverTripLink::THANKS_AR) }}</p>

        <div class="mt-3 flex items-center gap-2 text-xs font-semibold">
            <span class="rounded-full px-2.5 py-1 {{ $started ? 'bg-emerald-100 text-emerald-700' : 'bg-primary-400 text-chrome-900' }}">1 · Start / البدء</span>
            <span class="rounded-full px-2.5 py-1 {{ $ended ? 'bg-emerald-100 text-emerald-700' : ($started ? 'bg-primary-400 text-chrome-900' : 'bg-chrome-100 text-chrome-500') }}">2 · End / الإنهاء</span>
        </div>
    </div>

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5" data-trip-details>
        <h2 class="text-sm font-semibold text-chrome-800">Trip details <span class="ar">· تفاصيل الرحلة</span></h2>
        <dl class="mt-2 divide-y divide-chrome-100 text-sm">
            @foreach ($details as $row)
                <div class="py-2 {{ isset($row['locked']) ? 'rounded-lg bg-amber-50 px-2' : '' }}" @isset($row['locked']) data-address-locked @endisset>
                    <dt class="text-xs text-chrome-500">{{ $row['label'] }}</dt>
                    <dd class="whitespace-pre-line font-medium text-chrome-900">
                        @if (isset($row['url']))
                            <a href="{{ $row['url'] }}" class="text-primary-700 underline" target="_blank" rel="noopener">{{ $row['value'] }}</a>
                        @else
                            {{ $row['value'] }}
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>

    @if ($blocking !== null)
        <div class="rounded-2xl border-2 border-red-300 bg-red-50 p-5" data-trip-blocked>
            <p class="font-semibold text-red-800">Finish your other trip first: {{ $blocking->leg?->reference }}</p>
            <p class="ar font-semibold text-red-800">أنهِ رحلتك الأخرى أولاً: {{ $blocking->leg?->reference }}</p>
            <p class="mt-1 text-sm text-red-700">You can start this trip only after you press End trip on that one.</p>
            <p class="ar text-sm text-red-700">لا يمكنك بدء هذه الرحلة إلا بعد الضغط على «إنهاء الرحلة» في تلك الرحلة.</p>
            @if ($blockingUrl)
                <a href="{{ $blockingUrl }}" class="o-btn-primary mt-3 w-full justify-center py-3">Open that trip / افتح تلك الرحلة</a>
            @endif
        </div>
    @elseif ($ended)
        <div class="rounded-2xl bg-emerald-50 p-5 ring-1 ring-emerald-200" data-trip-ended>
            <p class="font-semibold text-emerald-800">Trip finished. Thank you!</p>
            <p class="ar font-semibold text-emerald-800">انتهت الرحلة. شكراً لك!</p>
            <p class="mt-2 text-sm text-emerald-800">{{ $run->started_at?->isoFormat('HH:mm') }} → {{ $run->ended_at?->isoFormat('HH:mm') }} · {{ number_format((int) $run->start_km) }} → {{ number_format((int) $run->end_km) }} km</p>
        </div>
    @else
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5" data-trip-step="{{ $stage }}">
            @if ($started)
                <p class="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">Trip started at {{ $run->started_at?->isoFormat('HH:mm') }} · {{ number_format((int) $run->start_km) }} km <span class="ar block">بدأت الرحلة الساعة {{ $run->started_at?->isoFormat('HH:mm') }}</span></p>
                <h2 class="font-semibold text-chrome-900">When you finish: photo of the dashboard</h2>
                <p class="ar font-semibold text-chrome-900">عند الانتهاء: صوّر لوحة العدادات</p>
            @else
                <h2 class="font-semibold text-chrome-900">Before you leave: photo of the dashboard</h2>
                <p class="ar font-semibold text-chrome-900">قبل التحرك: صوّر لوحة العدادات</p>
            @endif
            <p class="mt-1 text-sm text-chrome-600">The KM and the fuel must both be clear in the photo.</p>
            <p class="ar text-sm text-chrome-600">يجب أن يظهر عداد الكيلومترات ومؤشر الوقود بوضوح في الصورة.</p>

            <label class="o-btn-{{ $hasPhoto ? 'ghost ring-1 ring-chrome-200' : 'primary' }} mt-4 w-full cursor-pointer justify-center py-3" data-photo-pick>
                <span id="photoLabel">{{ $hasPhoto ? 'Take the photo again / أعد التصوير' : 'Take the photo / التقط الصورة' }}</span>
                <input type="file" accept="image/*" capture="environment" class="hidden" id="photo">
            </label>

            <div id="confirm" class="mt-4 space-y-3" @if (! $hasPhoto) hidden @endif>
                <p id="readNote" class="text-xs text-chrome-500">Check the numbers. Correct them if wrong. / تحقق من الأرقام وصححها إن كانت خاطئة.</p>
                <label class="block text-sm font-medium text-chrome-700">KM / عداد الكيلومترات
                    <input type="number" inputmode="numeric" min="1" id="km" class="o-input mt-1 w-full text-lg" value="{{ ($read['km'] ?? 0) > 0 ? $read['km'] : '' }}">
                </label>
                <label class="block text-sm font-medium text-chrome-700">Fuel / الوقود
                    <select id="fuel" class="o-input mt-1 w-full text-lg">
                        @foreach ($fuels as $i => $label)
                            <option value="{{ $i }}" @selected((int) ($read['fuel_eighths'] ?? 4) === $i)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="button" id="go" class="w-full rounded-xl py-4 text-lg font-bold text-white {{ $started ? 'bg-red-600' : 'bg-emerald-600' }}" data-trip-go="{{ $stage }}">
                    {{ $started ? 'End trip / إنهاء الرحلة' : 'Start trip / بدء الرحلة' }}
                </button>
                <p class="text-xs text-chrome-500">Your location is saved when you press the button. / يُحفظ موقعك عند الضغط على الزر.</p>
            </div>

            <p id="busy" hidden class="mt-4 text-sm font-medium text-chrome-600">Please wait… / يرجى الانتظار…</p>
            <p id="error" hidden class="mt-4 text-sm font-medium text-red-600" role="alert"></p>
        </div>
    @endif


    @if (! $ended && $blocking === null)
    <script>
        (function () {
            var url = @js($postUrl), stage = @js($stage);
            var token = document.querySelector('meta[name="csrf-token"]').content;
            var $ = function (id) { return document.getElementById(id); };
            function busy(on) { $('busy').hidden = !on; $('go') && ($('go').disabled = on); }
            function fail(msg) { busy(false); $('error').textContent = msg || 'Something went wrong. Try again. / حدث خطأ، حاول مرة أخرى.'; $('error').hidden = false; }
            function post(body) {
                body.append('_token', token);
                return fetch(url, { method: 'POST', body: body, headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token } })
                    .then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw new Error(j.message || ''); } return j; }); });
            }
            $('photo').addEventListener('change', function (e) {
                var file = e.target.files[0]; if (!file) { return; }
                $('error').hidden = true; busy(true);
                var fd = new FormData(); fd.append('action', 'photo'); fd.append('file', file);
                post(fd).then(function (j) {
                    busy(false);
                    $('confirm').hidden = false;
                    $('photoLabel').textContent = 'Take the photo again / أعد التصوير';
                    if (j.readable && j.km > 0) { $('km').value = j.km; } else { $('km').value = ''; $('readNote').textContent = 'The KM could not be read. Type it. / لم تتم قراءة العداد، اكتبه.'; }
                    if (j.fuel_readable) { $('fuel').value = String(j.fuel_eighths); }
                    $('km').focus();
                }).catch(function (err) { fail(err.message); });
                e.target.value = '';
            });
            function send(pos) {
                var fd = new FormData();
                fd.append('action', stage); fd.append('km', $('km').value); fd.append('fuel', $('fuel').value);
                if (pos) { fd.append('lat', pos.coords.latitude); fd.append('lng', pos.coords.longitude); fd.append('accuracy', pos.coords.accuracy); }
                post(fd).then(function () { location.reload(); }).catch(function (err) { fail(err.message); });
            }
            $('go') && $('go').addEventListener('click', function () {
                if (!$('km').value) { fail('Type the KM. / اكتب عدد الكيلومترات.'); return; }
                $('error').hidden = true; busy(true);
                if (!navigator.geolocation) { send(null); return; }
                navigator.geolocation.getCurrentPosition(send, function () {
                    if (confirm('Your location could not be read. Allow location for this page and try again, or press OK to continue without it (the office will see that).\n\nتعذر قراءة موقعك. اسمح بالموقع لهذه الصفحة وحاول مرة أخرى، أو اضغط موافق للمتابعة بدونه (سيرى المكتب ذلك).')) { send(null); } else { busy(false); }
                }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
            });
        })();
    </script>
    @endif
@endif
</div>
</body>
</html>
