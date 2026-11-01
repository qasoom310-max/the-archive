@php
    $setting = static fn (string $key): string => trim((string) \App\Erp\Settings\Setting::get($key));
    $company = $setting('company.name') ?: 'Wanaan';
    $email = $setting('company.email') ?: 'info@wanaan-bh.com';
    $phones = array_values(array_filter([$setting('company.phone'), $setting('company.phone_alt')]));
    $address = $setting('company.address');
    $website = $setting('company.website');
    $cr = $setting('company.cr_number');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wanaan Staff — Support</title>
    <style>
        body { margin: 0; background: #f4f4f5; color: #27272a; font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif; }
        main { max-width: 760px; margin: 0 auto; padding: 24px 18px 48px; }
        header { background: #facc15; padding: 20px 18px; }
        header h1 { margin: 0 auto; max-width: 760px; font-size: 22px; color: #18181b; }
        section { background: #fff; border: 1px solid #e4e4e7; border-radius: 14px; padding: 18px 20px; margin-top: 16px; }
        h2 { font-size: 18px; margin: 18px 0 6px; color: #18181b; }
        dl { margin: 0; }
        dt { font-weight: 700; margin-top: 10px; }
        dd { margin: 0; white-space: pre-line; }
        .ar { direction: rtl; text-align: right; }
        a { color: #a16207; }
    </style>
</head>
<body>
<header><h1>Wanaan Staff — Support · الدعم</h1></header>
<main>
    <section>
        <p><strong>Wanaan Staff</strong> is the work app of {{ $company }}'s staff: the trips waiting for a car and driver, and assigning them from the phone. Accounts are created by the company — there is no sign-up.</p>

        <h2>Help with the app</h2>
        <p>Ask your manager first — they can reset your password or your access. Otherwise, contact {{ $company }}:</p>

        <dl>
            <dt>Email</dt>
            <dd><a href="mailto:{{ $email }}?subject={{ rawurlencode('Wanaan Staff app') }}">{{ $email }}</a></dd>
            @foreach ($phones as $phone)
                <dt>Phone</dt>
                <dd><a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}">{{ $phone }}</a></dd>
            @endforeach
            @if ($address !== '')
                <dt>Address</dt>
                <dd>{{ $address }}</dd>
            @endif
            @if ($cr !== '')
                <dt>Commercial registration</dt>
                <dd>{{ $cr }}</dd>
            @endif
            @if ($website !== '')
                <dt>Website</dt>
                <dd><a href="{{ str_starts_with($website, 'http') ? $website : 'https://' . $website }}">{{ $website }}</a></dd>
            @endif
        </dl>

        <h2>Common questions</h2>
        <p><strong>I can't sign in.</strong> Use the same email (or username) and password as the company's ERP. After too many wrong passwords the account waits 15 minutes; a paused account is reopened by your manager.</p>
        <p><strong>I get no reminders.</strong> Allow notifications for Wanaan Staff in the phone's settings. The app still works without them — open the "Needs car &amp; driver" list.</p>
        <p><strong>The trip details are hidden.</strong> For operations staff, a trip's details open once it has a car and a driver — assign both and they appear.</p>

        <p>See also the <a href="{{ route('staff-app.privacy') }}">privacy policy</a> and <a href="{{ route('staff-app.account-deletion') }}">account deletion</a>.</p>
    </section>

    <section class="ar" lang="ar">
        <p><strong>وَنان للموظفين</strong> هو تطبيق العمل لموظفي {{ $company }}: الرحلات التي تنتظر سيارة وسائقاً، وتعيينهما من الهاتف. تُنشئ الشركة الحسابات — لا يوجد تسجيل ذاتي.</p>

        <h2>المساعدة في التطبيق</h2>
        <p>اسأل مديرك أولاً — يمكنه إعادة تعيين كلمة المرور أو الصلاحيات. أو تواصل مع {{ $company }}:</p>

        <dl>
            <dt>البريد الإلكتروني</dt>
            <dd><a href="mailto:{{ $email }}">{{ $email }}</a></dd>
            @foreach ($phones as $phone)
                <dt>الهاتف</dt>
                <dd><a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}" dir="ltr">{{ $phone }}</a></dd>
            @endforeach
            @if ($address !== '')
                <dt>العنوان</dt>
                <dd>{{ $address }}</dd>
            @endif
            @if ($cr !== '')
                <dt>السجل التجاري</dt>
                <dd>{{ $cr }}</dd>
            @endif
        </dl>

        <h2>أسئلة شائعة</h2>
        <p><strong>لا أستطيع تسجيل الدخول.</strong> استخدم نفس البريد الإلكتروني (أو اسم المستخدم) وكلمة المرور الخاصة بنظام الشركة. بعد محاولات خاطئة كثيرة ينتظر الحساب 15 دقيقة، ويعيد مديرك فتح الحساب الموقوف.</p>
        <p><strong>لا تصلني التذكيرات.</strong> اسمح بالإشعارات لتطبيق وَنان للموظفين من إعدادات الهاتف. يعمل التطبيق بدونها أيضاً — افتح قائمة «بحاجة إلى سيارة وسائق».</p>
        <p><strong>تفاصيل الرحلة مخفية.</strong> لموظفي العمليات، تظهر تفاصيل الرحلة بعد تعيين السيارة والسائق.</p>

        <p>راجع أيضاً <a href="{{ route('staff-app.privacy') }}">سياسة الخصوصية</a> و<a href="{{ route('staff-app.account-deletion') }}">حذف الحساب</a>.</p>
    </section>
</main>
</body>
</html>
