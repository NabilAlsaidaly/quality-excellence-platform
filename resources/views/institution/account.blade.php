<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><title>الحساب المؤسسي</title></head>
<body style="font-family:Tahoma,Arial,sans-serif;max-width:40rem;margin:2rem auto;padding:1rem;line-height:1.8">
<main>
    <h1>الحساب المؤسسي</h1>
    <p>أهلًا {{ $account->name }}. تم التحقق من حسابك وسياق المؤسسة.</p>
    <p>هذه صفحة الحساب فقط. لا تمنح الوصول إلى محتوى المؤسسة أو صلاحيات إدارتها.</p>
    @if ($account->canAccessPanel(\Filament\Facades\Filament::getPanel('institution')))
        <p><a href="{{ route('filament.institution.pages.institution-dashboard', [], false) }}">فتح مساحة المؤسسة</a></p>
    @endif
    <form method="post" action="{{ route('institution.logout', ['organization' => request()->route('organization')], false) }}">
        @csrf
        <button type="submit" style="min-height:44px">تسجيل الخروج</button>
    </form>
</main>
</body></html>
