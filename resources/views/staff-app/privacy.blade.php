@php
    $company = (string) (\App\Erp\Settings\Setting::get('company.name') ?: 'Wanaan');
    $email = (string) (\App\Erp\Settings\Setting::get('company.email') ?: 'info@wanaan-bh.com');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wanaan Staff — Privacy Policy</title>
    <style>
        body { margin: 0; background: #f4f4f5; color: #27272a; font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif; }
        main { max-width: 760px; margin: 0 auto; padding: 24px 18px 48px; }
        header { background: #facc15; padding: 20px 18px; }
        header h1 { margin: 0 auto; max-width: 760px; font-size: 22px; color: #18181b; }
        section { background: #fff; border: 1px solid #e4e4e7; border-radius: 14px; padding: 18px 20px; margin-top: 16px; }
        h2 { font-size: 18px; margin: 18px 0 6px; color: #18181b; }
        .ar { direction: rtl; text-align: right; }
        a { color: #a16207; }
        .muted { color: #71717a; font-size: 14px; }
    </style>
</head>
<body>
<header><h1>Wanaan Staff — Privacy Policy · سياسة الخصوصية</h1></header>
<main>
    <p class="muted">Last updated: 31 October 2026</p>

    <section>
        <p><strong>Wanaan Staff</strong> is the work app of {{ $company }} (Bahrain) for its own employees. It is not offered to the public: every account is created by {{ $company }} for a member of its staff. This policy explains what the app handles and why.</p>

        <h2>What the app handles</h2>
        <ul>
            <li><strong>Your staff account</strong> — your name and the email or username you sign in with. Your password is never stored on the phone.</li>
            <li><strong>A sign-in token</strong>, kept in the phone's secure storage (iOS Keychain / Android Keystore), so you stay signed in. It expires after 60 days, and is deleted when you sign out.</li>
            <li><strong>Your phone's notification address</strong> (a push token), so the company's system can send you work reminders — for example, a trip that still needs a car and a driver. It is removed when you sign out.</li>
            <li><strong>Work information from {{ $company }}'s system</strong> that your role allows you to see: trips, their times and places, customer and passenger names and contact numbers. It is shown to you for your work, and is not stored by the app after you leave the screen.</li>
            <li><strong>What you do in the app</strong> — such as assigning a car and a driver to a trip — is recorded in the company system, the same as when it is done on the company website.</li>
        </ul>

        <h2>What the app does not do</h2>
        <ul>
            <li>No advertising, and no tracking of you across other apps or websites.</li>
            <li>No sale of any data, and no sharing with anyone outside the service providers below.</li>
            <li>The app does not use your location.</li>
        </ul>

        <h2>Service providers</h2>
        <p>The company system is hosted by its web host. Notifications are delivered through Expo's notification service and, from there, Apple (iPhone) or Google (Android); they receive only the notification text and your phone's notification address.</p>

        <h2>Security</h2>
        <p>Everything travels over encrypted connections (HTTPS). Access inside the system follows the permissions {{ $company }} gives your account.</p>

        <h2>Keeping and deleting data</h2>
        <p>Your account is kept while you work with {{ $company }}. You can ask for your account and the data linked to it to be deleted at any time — in the app (Account → Delete my account) or on the <a href="{{ route('staff-app.account-deletion') }}">Account deletion</a> page. Company records of the work itself (such as which car and driver served a trip) may be kept where the law or the company's accounting requires it.</p>

        <h2>Contact</h2>
        <p>Questions about this policy or your data: <a href="mailto:{{ $email }}">{{ $email }}</a>, or see <a href="{{ route('staff-app.support') }}">Support</a>.</p>
    </section>

    <section class="ar" lang="ar">
        <p><strong>تطبيق وَنان للموظفين</strong> هو تطبيق العمل الخاص بـ {{ $company }} (البحرين) لموظفيها فقط، ولا يُتاح للعامة؛ إذ تُنشئ الشركة كل حساب لأحد موظفيها. توضّح هذه السياسة ما يتعامل معه التطبيق ولماذا.</p>

        <h2>ما يتعامل معه التطبيق</h2>
        <ul>
            <li><strong>حساب الموظف</strong> — اسمك والبريد الإلكتروني أو اسم المستخدم الذي تسجّل به الدخول. لا تُحفظ كلمة المرور على الهاتف أبداً.</li>
            <li><strong>رمز تسجيل الدخول</strong>، ويُحفظ في التخزين الآمن للهاتف، وتنتهي صلاحيته بعد 60 يوماً، ويُحذف عند تسجيل الخروج.</li>
            <li><strong>عنوان الإشعارات في هاتفك</strong>، ليرسل لك نظام الشركة تذكيرات العمل، مثل رحلة ما زالت بحاجة إلى سيارة وسائق. ويُحذف عند تسجيل الخروج.</li>
            <li><strong>معلومات العمل من نظام الشركة</strong> التي يسمح بها دورك: الرحلات ومواعيدها وأماكنها، وأسماء العملاء والركاب وأرقام التواصل. تُعرض لك لأداء عملك، ولا يحتفظ بها التطبيق بعد مغادرة الشاشة.</li>
            <li><strong>ما تقوم به في التطبيق</strong> — مثل تعيين سيارة وسائق لرحلة — يُسجَّل في نظام الشركة كما لو تم من موقع الشركة.</li>
        </ul>

        <h2>ما لا يفعله التطبيق</h2>
        <ul>
            <li>لا إعلانات، ولا تتبّع لك عبر التطبيقات أو المواقع الأخرى.</li>
            <li>لا بيع لأي بيانات، ولا مشاركة مع أي جهة غير مزوّدي الخدمة المذكورين أدناه.</li>
            <li>لا يستخدم التطبيق موقعك.</li>
        </ul>

        <h2>مزوّدو الخدمة</h2>
        <p>يستضيف نظامَ الشركة مزوّدُ الاستضافة. وتُرسل الإشعارات عبر خدمة Expo ثم Apple (آيفون) أو Google (أندرويد)، ولا يصلها إلا نص الإشعار وعنوان الإشعارات في هاتفك.</p>

        <h2>الحماية</h2>
        <p>تنتقل جميع البيانات عبر اتصال مشفّر (HTTPS)، وتخضع الصلاحيات داخل النظام لما تمنحه الشركة لحسابك.</p>

        <h2>الاحتفاظ بالبيانات وحذفها</h2>
        <p>يبقى حسابك ما دمت تعمل مع الشركة، ويمكنك طلب حذف حسابك والبيانات المرتبطة به في أي وقت — من التطبيق (الحساب ← حذف حسابي) أو من صفحة <a href="{{ route('staff-app.account-deletion') }}">حذف الحساب</a>. وقد تحتفظ الشركة بسجلات العمل نفسه (مثل السيارة والسائق اللذين خدما رحلة) حيث يتطلب القانون أو المحاسبة ذلك.</p>

        <h2>التواصل</h2>
        <p>للاستفسار عن هذه السياسة أو بياناتك: <a href="mailto:{{ $email }}">{{ $email }}</a>، أو راجع <a href="{{ route('staff-app.support') }}">الدعم</a>.</p>
    </section>
</main>
</body>
</html>
