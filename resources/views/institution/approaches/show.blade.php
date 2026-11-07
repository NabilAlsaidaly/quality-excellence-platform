{{-- NOT ACTIVATED: unregistered template; metadata only, never an approved-content claim. --}}
<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $approach['name'] }}</title></head>
<body style="font-family:Tahoma,sans-serif;line-height:1.8;max-width:70rem;margin:auto;padding:1rem;overflow-wrap:anywhere">
<main>
    <h1>{{ $approach['name'] }}</h1>
    <p role="status">قالب داخلي غير مفعّل — لا يدل حفظ البيانات على اعتماد المنهجية أو نشرها.</p>
    <dl>
        <dt>المعرّف</dt><dd><bdi>{{ $approach['public_id'] }}</bdi></dd>
        <dt>الوصف</dt><dd>{{ $approach['description'] }}</dd>
        <dt>الغاية</dt><dd>{{ $approach['purpose'] }}</dd>
        <dt>النطاق</dt><dd>{{ $approach['scope'] }}</dd>
        <dt>الوحدة المالكة</dt><dd>{{ $approach['owning_unit']['name'] }}</dd>
        <dt>الأهداف</dt><dd>{{ $approach['objectives'] ?? 'لم تُستكمل بعد' }}</dd>
        <dt>دورية المراجعة</dt><dd>{{ $approach['review_frequency'] ?? 'لم تُستكمل بعد' }}</dd>
    </dl>
</main>
</body>
</html>
