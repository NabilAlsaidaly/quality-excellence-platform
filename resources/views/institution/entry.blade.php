<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><title>دخول الحساب المؤسسي</title></head>
<body style="font-family:Tahoma,Arial,sans-serif;max-width:40rem;margin:2rem auto;padding:1rem;line-height:1.8">
    <main>
        <h1>دخول الحساب المؤسسي</h1>
        <p>أدخل معرّف المؤسسة الذي استلمته من الجهة المخولة. لا تتوفر قائمة عامة بالمؤسسات.</p>
        <p>بيئة اختبار: الدخول لا يمنح صلاحيات إدارة أو وصولًا إلى وثائق المؤسسة.</p>
        @if(session('status')) <p role="status">{{ session('status') }}</p> @endif
        @if($errors->any()) <div role="alert">{{ $errors->first() }}</div> @endif
        <form method="post" action="{{ route('institution.login', [], false) }}">
            @csrf
            <p><label for="organization">معرّف المؤسسة</label><br><input id="organization" name="organization" value="{{ old('organization') }}" required maxlength="128" dir="ltr" autocomplete="organization" style="width:100%;min-height:44px;box-sizing:border-box"></p>
            <p><label for="email">البريد المسجل</label><br><input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="254" dir="ltr" autocomplete="username" style="width:100%;min-height:44px;box-sizing:border-box"></p>
            <p><label for="password">كلمة المرور</label><br><input id="password" name="password" type="password" required autocomplete="current-password" style="width:100%;min-height:44px;box-sizing:border-box"></p>
            <button type="submit" style="min-height:44px">تسجيل الدخول</button>
        </form>
        <h2>استعادة الوصول</h2>
        <p>حدد المؤسسة والبريد للحساب الذي تريد استعادته.</p>
        <form method="post" action="{{ route('institution.recovery', [], false) }}">
            @csrf
            <p><label for="recovery-organization">معرّف المؤسسة</label><br><input id="recovery-organization" name="organization" required maxlength="128" dir="ltr" style="width:100%;min-height:44px;box-sizing:border-box"></p>
            <p><label for="recovery-email">البريد المسجل</label><br><input id="recovery-email" name="email" type="email" required maxlength="254" dir="ltr" autocomplete="email" style="width:100%;min-height:44px;box-sizing:border-box"></p>
            <button type="submit" style="min-height:44px">طلب تعليمات الاستعادة</button>
        </form>
    </main>
</body></html>
