<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><title>تعيين كلمة مرور جديدة</title></head>
<body style="font-family:Tahoma,Arial,sans-serif;max-width:40rem;margin:2rem auto;padding:1rem;line-height:1.8">
<main>
    <h1>تعيين كلمة مرور جديدة</h1>
    <p>الرابط خاص بالحساب والمؤسسة المحددين في طلب الاستعادة.</p>
    @if($errors->any()) <div role="alert">{{ $errors->first() }}</div> @endif
    <form method="post" action="{{ request()->getRequestUri() }}">
        @csrf
        <p><label for="password">كلمة المرور الجديدة</label><br><input id="password" name="password" type="password" required minlength="12" maxlength="128" autocomplete="new-password" style="width:100%;min-height:44px;box-sizing:border-box"></p>
        <p><label for="confirmation">تأكيد كلمة المرور</label><br><input id="confirmation" name="password_confirmation" type="password" required minlength="12" maxlength="128" autocomplete="new-password" style="width:100%;min-height:44px;box-sizing:border-box"></p>
        <button type="submit" style="min-height:44px">حفظ كلمة المرور</button>
    </form>
</main>
</body></html>
