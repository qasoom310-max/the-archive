@php
    $company = (string) (\App\Erp\Settings\Setting::get('company.name') ?: 'Wanaan');
    $email = (string) (\App\Erp\Settings\Setting::get('company.email') ?: 'info@wanaan-bh.com');
    $subject = rawurlencode('Delete my Wanaan Staff account');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wanaan Staff — Account deletion</title>
    <style>
        body { margin: 0; background: #f4f4f5; color: #27272a; font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif; }
        main { max-width: 760px; margin: 0 auto; padding: 24px 18px 48px; }
        header { background: #facc15; padding: 20px 18px; }
        header h1 { margin: 0 auto; max-width: 760px; font-size: 22px; color: #18181b; }
        section { background: #fff; border: 1px solid #e4e4e7; border-radius: 14px; padding: 18px 20px; margin-top: 16px; }
        h2 { font-size: 18px; margin: 18px 0 6px; color: #18181b; }
        .ar { direction: rtl; text-align: right; }
        a { color: #a16207; }
        .button { display: inline-block; background: #facc15; color: #18181b; font-weight: 700; padding: 12px 18px; border-radius: 12px; text-decoration: none; margin-top: 6px; }
    </style>
</head>
<body>
<header><h1>Wanaan Staff — Account deletion · حذف الحساب</h1></header>
<main>
    <section>
        <p>Accounts in the <strong>Wanaan Staff</strong> app are created by {{ $company }} for its staff. To have your account and the data linked to it deleted, send the request from the email address on your account (or ask your administrator):</p>
        <p><a class="button" href="mailto:{{ $email }}?subject={{ $subject }}">Request deletion — {{ $email }}</a></p>

        <h2>What is deleted</h2>
        <ul>
            <li>Your staff account: name, email or username, password.</li>
            <li>Every sign-in on every phone (sign-in tokens), and your phones' notification addresses.</li>
        </ul>

        <h2>What may be kept</h2>
        <p>Company records of the work itself — for example which car and driver served a trip — may be kept where the law or the company's accounting requires it; they no longer give anyone access to an account.</p>

        <h2>How long it takes</h2>
        <p>The request is completed within 30 days, and you will be told by email when it is done.</p>

        <p>See also the <a href="{{ route('staff-app.privacy') }}">privacy policy</a>.</p>
    </section>

    <section class="ar" lang="ar">
        <p>تُنشئ {{ $company }} حسابات تطبيق <strong>وَنان للموظفين</strong> لموظفيها. لحذف حسابك والبيانات المرتبطة به، أرسل الطلب من البريد الإلكتروني المسجّل في حسابك (أو اطلب ذلك من المسؤول):</p>
        <p><a class="button" href="mailto:{{ $email }}?subject={{ $subject }}">طلب الحذف — {{ $email }}</a></p>

        <h2>ما يُحذف</h2>
        <ul>
            <li>حساب الموظف: الاسم، والبريد الإلكتروني أو اسم المستخدم، وكلمة المرور.</li>
            <li>جميع جلسات الدخول على جميع الهواتف، وعناوين الإشعارات لهواتفك.</li>
        </ul>

        <h2>ما قد يُحتفظ به</h2>
        <p>قد تحتفظ الشركة بسجلات العمل نفسه — مثل السيارة والسائق اللذين خدما رحلة — حيث يتطلب القانون أو المحاسبة ذلك، ولا تمنح هذه السجلات أي وصول إلى حساب.</p>

        <h2>المدة</h2>
        <p>يُنفَّذ الطلب خلال 30 يوماً، وسيتم إبلاغك بالبريد الإلكتروني عند الانتهاء.</p>

        <p>راجع أيضاً <a href="{{ route('staff-app.privacy') }}">سياسة الخصوصية</a>.</p>
    </section>
</main>
</body>
</html>
