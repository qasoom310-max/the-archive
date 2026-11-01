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
        label { display: block; font-weight: 600; margin-top: 14px; }
        label small { font-weight: 400; color: #71717a; }
        input, textarea { box-sizing: border-box; width: 100%; margin-top: 4px; padding: 10px 12px; border: 1px solid #d4d4d8; border-radius: 10px; font: inherit; background: #fff; }
        textarea { min-height: 90px; }
        .button { display: inline-block; background: #facc15; color: #18181b; padding: 12px 18px; border-radius: 12px; border: 0; font: inherit; font-weight: 700; cursor: pointer; text-decoration: none; margin-top: 16px; }
        .done { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
        .error { color: #b91c1c; font-size: 14px; margin: 4px 0 0; }
        .trap { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
    </style>
</head>
<body>
<header><h1>Wanaan Staff — Account deletion · حذف الحساب</h1></header>
<main>
    @if (session('deletion_requested'))
        <section class="done" role="status">
            <p><strong>Your request has been received.</strong> {{ $company }} will delete the account and the data below within 30 days, and confirm by email.</p>
            <p class="ar" lang="ar"><strong>تم استلام طلبك.</strong> ستحذف {{ $company }} الحساب والبيانات المذكورة أدناه خلال 30 يوماً، وتؤكد ذلك بالبريد الإلكتروني.</p>
        </section>
    @endif

    <section>
        <p>Accounts in the <strong>Wanaan Staff</strong> app are created by {{ $company }} for its staff. To have your account and the data linked to it deleted, fill in this form. You can also ask from inside the app (Account → Delete my account), or by email.</p>

        <form method="post" action="{{ route('staff-app.account-deletion.request') }}">
            @csrf
            <label for="name">Your name · الاسم</label>
            <input id="name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name">
            @error('name')<p class="error">{{ $message }}</p>@enderror

            <label for="login">The email or username you sign in with · البريد الإلكتروني أو اسم المستخدم للدخول</label>
            <input id="login" name="login" value="{{ old('login') }}" required maxlength="191" autocomplete="username">
            @error('login')<p class="error">{{ $message }}</p>@enderror

            <label for="contact_email">Email for the confirmation <small>(if you sign in with a username)</small> · بريد لتأكيد الحذف</label>
            <input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email') }}" maxlength="191" autocomplete="email">
            @error('contact_email')<p class="error">{{ $message }}</p>@enderror

            <label for="note">Anything we should know <small>(optional)</small> · ملاحظات</label>
            <textarea id="note" name="note" maxlength="1000">{{ old('note') }}</textarea>
            @error('note')<p class="error">{{ $message }}</p>@enderror

            <div class="trap" aria-hidden="true">
                <label for="website">Leave this empty</label>
                <input id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <button type="submit" class="button">Request deletion · طلب الحذف</button>
        </form>

        <p>Or by email: <a href="mailto:{{ $email }}?subject={{ $subject }}">{{ $email }}</a></p>

        <h2>What is deleted</h2>
        <ul>
            <li>Your staff account: name, email or username, password.</li>
            <li>Every sign-in on every phone (sign-in tokens), and your phones' notification addresses.</li>
        </ul>

        <h2>What may be kept</h2>
        <p>Company records of the work itself — for example which car and driver served a trip, and who assigned them — may be kept where the law or the company's accounting requires it; they no longer give anyone access to an account.</p>

        <h2>How long it takes</h2>
        <p>The request is completed within 30 days, and you will be told by email when it is done.</p>

        <p>See also the <a href="{{ route('staff-app.privacy') }}">privacy policy</a> and <a href="{{ route('staff-app.support') }}">support</a>.</p>
    </section>

    <section class="ar" lang="ar">
        <p>تُنشئ {{ $company }} حسابات تطبيق <strong>وَنان للموظفين</strong> لموظفيها. لحذف حسابك والبيانات المرتبطة به، املأ النموذج أعلاه. يمكنك أيضاً الطلب من داخل التطبيق (الحساب ← حذف حسابي)، أو بالبريد الإلكتروني: <a href="mailto:{{ $email }}?subject={{ $subject }}">{{ $email }}</a></p>

        <h2>ما يُحذف</h2>
        <ul>
            <li>حساب الموظف: الاسم، والبريد الإلكتروني أو اسم المستخدم، وكلمة المرور.</li>
            <li>جميع جلسات الدخول على جميع الهواتف، وعناوين الإشعارات لهواتفك.</li>
        </ul>

        <h2>ما قد يُحتفظ به</h2>
        <p>قد تحتفظ الشركة بسجلات العمل نفسه — مثل السيارة والسائق اللذين خدما رحلة ومن قام بتعيينهما — حيث يتطلب القانون أو المحاسبة ذلك، ولا تمنح هذه السجلات أي وصول إلى حساب.</p>

        <h2>المدة</h2>
        <p>يُنفَّذ الطلب خلال 30 يوماً، وسيتم إبلاغك بالبريد الإلكتروني عند الانتهاء.</p>

        <p>راجع أيضاً <a href="{{ route('staff-app.privacy') }}">سياسة الخصوصية</a> و<a href="{{ route('staff-app.support') }}">الدعم</a>.</p>
    </section>
</main>
</body>
</html>
