{{-- NOT ACTIVATED: caller must separately authorize draft history, not general-reader access. --}}
<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>مسودات محتوى المنهجية</title></head>
<body style="font-family:Tahoma,sans-serif;line-height:1.8;max-width:70rem;margin:auto;padding:1rem">
<main>
    <h1>مسودات محتوى المنهجية</h1>
    <p role="status">قالب داخلي غير مفعّل — هذه مسودات محفوظة وليست إصدارات معتمدة أو منشورة.</p>
    @forelse ($revisions as $revision)
        <article>
            <h2>مسودة رقم {{ $revision['version_number'] }}</h2>
            <p>وقت الحفظ: <bdi>{{ $revision['created_at'] }}</bdi></p>
            <pre style="white-space:pre-wrap;overflow-wrap:anywhere;font:inherit">{{ $revision['body'] }}</pre>
        </article>
    @empty
        <p>لم يُحفظ محتوى بعد.</p>
    @endforelse
</main>
</body>
</html>
