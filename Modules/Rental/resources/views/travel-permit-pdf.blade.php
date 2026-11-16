{{--
    Travel authorization, laid out like the traffic directorate's own form.

    DomPDF lays text out left to right and ShapedPdf writes each Arabic piece
    in visual order, so every line below lists its pieces from the LEFT of the
    page to the right — the reverse of how the sentence is read — and is
    aligned to the right edge. The filled-in values sit on dotted rules, as on
    the paper form.
--}}
@php
    // ShapedPdf breaks any Arabic text node longer than ArabicText::LINE_CHARS
    // onto a new line, which is right for a table cell and wrong for a form
    // whose lines must stay whole. So a sentence is cut at word gaps into
    // pieces under that length, each in its own inline box, written from the
    // left of the page to the right (the reverse of reading order).
    $rtl = static function (string $sentence): string {
        $pieces = [];
        $piece = '';
        foreach (preg_split('/\s+/u', trim($sentence)) ?: [] as $word) {
            if ($piece !== '' && mb_strlen($piece . ' ' . $word) > \App\Erp\Pdf\ArabicText::LINE_CHARS - 2) {
                $pieces[] = $piece;
                $piece = $word;
            } else {
                $piece = $piece === '' ? $word : $piece . ' ' . $word;
            }
        }
        if ($piece !== '') {
            $pieces[] = $piece;
        }

        return implode('', array_map(static fn (string $p): string => '<span class="t">' . e($p) . '</span>', array_reverse($pieces)));
    };
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 42mm 24mm 20mm 24mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12.5pt; color: #111; }
        .title { text-align: center; font-size: 13.5pt; line-height: 1.75; }
        .line { text-align: right; margin-top: 9pt; white-space: nowrap; }
        .gap { margin-top: 30pt; }
        .fill { display: inline-block; border-bottom: 1.3px dotted #000; padding: 0 9pt; text-align: center; font-size: 12.5pt; }
        .t { display: inline-block; padding: 0 2.2pt; }
        table.owner { width: 100%; margin-top: 16pt; border-collapse: collapse; }
        table.owner td { text-align: center; padding: 3pt 0; vertical-align: bottom; }
        .sign { position: relative; margin-top: 44pt; height: 150pt; }
        .sign .label { position: absolute; right: 0; top: 0; }
        .sign img.signature { position: absolute; right: 95pt; top: -6pt; max-width: 150pt; max-height: 70pt; }
        .sign img.stamp { position: absolute; right: 230pt; top: -10pt; max-width: 150pt; max-height: 150pt; }
    </style>
</head>
<body>
    <div class="title">{!! $rtl('توكيل للسفر بمركبة بحرينية') !!}</div>
    <div class="title">{!! $rtl('لدول مجلس التعاون الخليجي والدول الأخرى') !!}</div>

    <div class="line gap">
        <span class="t" style="padding-right: 6pt;">{{ $permit->issued_on->format('d/m/Y') }}</span>{!! $rtl('التاريخ :') !!}
    </div>

    <div class="line gap">{!! $rtl('حضرة الفاضل مدير الإدارة العامة للمرور') !!}</div>

    <div class="line">
        {!! $rtl('نوع اللوحة بحرينية،') !!}
        <span class="fill" style="min-width: 70pt;">{{ $permit->plate_no }}</span>
        {!! $rtl('وكلت أنا الموقع أدناه مالك المركبة') !!}
    </div>

    <div class="line">
        <span class="fill" style="min-width: 90pt;">{{ $permit->driver_cpr }}</span>
        {!! $rtl('الرقم الشخصي') !!}
        <span class="fill" style="min-width: 150pt;">{{ $permit->driver_name }}</span>
        {!! $rtl('السيد/') !!}
    </div>

    {{-- Two set lines: left to wrap by itself, the sentence's FIRST words
         would drop to the second line (the boxes fill from the left). --}}
    <div class="line">{!! $rtl('بالسفر بمركبتي الى أي دولة من دول مجلس التعاون أو دول أخرى دون أي') !!}</div>
    <div class="line" style="margin-top: 3pt;">{!! $rtl('مسئولية تخص الإدارة العامة للمرور وهذا توكيل مني بذلك.') !!}</div>

    <div class="line" style="margin-top: 14pt;">{!! $rtl('أوصاف المركبة:') !!}</div>

    <div class="line">
        <span class="fill" style="min-width: 48pt;">{{ $permit->color }}</span>
        {!! $rtl('اللون') !!}
        <span class="fill" style="min-width: 40pt;">{{ $permit->year }}</span>
        {!! $rtl('سنة الصنع') !!}
        <span class="fill" style="min-width: 62pt;">{{ $permit->model }}</span>
        {!! $rtl('النوع') !!}
        <span class="fill" style="min-width: 48pt;">{{ $permit->make }}</span>
        {!! $rtl('الصنع') !!}
    </div>

    <div class="line">
        <span class="fill" style="min-width: 84pt;">{{ $permit->valid_to->format('d/m/Y') }}</span>
        {!! $rtl('الى تاريخ') !!}
        <span class="fill" style="min-width: 84pt;">{{ $permit->valid_from->format('d/m/Y') }}</span>
        {!! $rtl('صلاحية التخويل من تاريخ') !!}
    </div>

    <table class="owner">
        <tr>
            <td style="width: 45%;">{!! $rtl('الرقم الشخصي') !!}</td>
            <td style="width: 55%;">{!! $rtl('اسم مالك المركبة') !!}</td>
        </tr>
        <tr>
            <td><span class="fill" style="min-width: 150pt;">{{ $permit->owner_no }}</span></td>
            <td><span class="fill" style="min-width: 190pt;">{{ $permit->owner_name }}</span></td>
        </tr>
    </table>

    <div class="sign">
        <div class="label">{!! $rtl('توقيع المالك:') !!}</div>
        @if ($signature)
            <img class="signature" src="{{ $signature }}" alt="">
        @endif
        @if ($stamp)
            <img class="stamp" src="{{ $stamp }}" alt="">
        @endif
    </div>
</body>
</html>
