{{-- NOT ACTIVATED: unregistered template; consume only a Policy-authorized ListApproaches result. --}}
<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>بنك المنهجيات</title></head>
<body style="font-family:Tahoma,sans-serif;line-height:1.8;max-width:70rem;margin:auto;padding:1rem">
<main>
    <h1>بنك المنهجيات</h1>
    <p role="status">قالب داخلي غير مفعّل — لا توجد عمليات متاحة من هذه الصفحة.</p>
    @if (!empty($error))<p role="alert">{{ $error }}</p>@endif
    <section aria-label="المنهجيات">
        @forelse ($approaches as $approach)
            <article>
                <h2>{{ $approach['name'] }}</h2>
                <p>{{ $approach['description'] }}</p>
                <p>الوحدة المالكة: {{ $approach['owning_unit']['name'] }}</p>
                <p>المعرّف: <bdi>{{ $approach['public_id'] }}</bdi></p>
            </article>
        @empty
            <p>لا توجد منهجيات ضمن نطاق القراءة.</p>
        @endforelse
    </section>
    <p>النتائج: {{ $approaches->total() }} — الصفحة {{ $approaches->currentPage() }} من {{ $approaches->lastPage() }}</p>
</main>
</body>
</html>
