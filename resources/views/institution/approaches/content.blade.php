<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>مسودات محتوى المنهجية</title>
<style>body{font-family:Tahoma,Arial,sans-serif;background:#f5f8fa;color:#182f3b;line-height:1.9;margin:0;overflow-wrap:anywhere}main{max-width:68rem;margin:auto;padding:clamp(1rem,3vw,2.5rem)}article,section,form,dl{background:white;border:1px solid #ccdce3;border-radius:.7rem;padding:1rem;margin-block:1rem}h1{line-height:1.5}label{display:block;font-weight:bold;margin-top:.8rem}input,textarea,select,button{font:inherit;box-sizing:border-box;max-width:100%}input:not([type=checkbox]),textarea,select{width:100%;border:1px solid #6f8995;border-radius:.35rem;padding:.5rem}textarea{min-height:7rem}button{background:#075e63;color:white;border:0;border-radius:.35rem;padding:.5rem 1rem;margin-block:.8rem;cursor:pointer}a{color:#075e63;text-underline-offset:.2rem}nav{display:flex;flex-wrap:wrap;gap:1rem}dt{font-weight:bold}dd{margin:0 0 .8rem;white-space:pre-wrap}pre{white-space:pre-wrap;overflow-wrap:anywhere;font:inherit}[role=alert]{color:#8d2020;border-inline-start:4px solid;padding:.7rem}[role=status]{background:#e7f2f2;padding:.8rem;border-radius:.4rem}:focus-visible{outline:3px solid #b97400;outline-offset:3px}.hint{color:#425d6b}.actions{display:flex;gap:1rem;flex-wrap:wrap}</style></head>
<body><main>
<h1>مسودات محتوى المنهجية</h1>
<p role="status">{{ ($operational ?? false) ? __('institution_approaches.draft_notice', [], 'ar') : 'قالب داخلي غير مفعّل — ينتظر اعتماد الصلاحيات والتفعيل المستقل.' }}</p>
@if (session('status'))<p role="status">{{ session('status') }}</p>@endif
@if (isset($errors) && $errors->any())<div role="alert"><p>تعذّر الحفظ. راجع الحقول التالية:</p><ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif

@if(($operational ?? false) && $canEdit)
<form method="post" action="{{ route('institution.approaches.content.store',['organization'=>$organization,'approach'=>$resource],false) }}">@csrf
<label for="body">محتوى مسودة جديدة *</label><textarea id="body" name="body" required maxlength="1000000">{{ old('body') }}</textarea>
<p class="hint">نص مرن بلا قالب إلزامي. الحفظ ينشئ نسخة تاريخية؛ لا يعني مراجعة أو اعتمادًا أو نشرًا.</p><button type="submit">حفظ مسودة</button></form>@endif
@forelse($revisions as $revision)<article><h2>مسودة رقم {{ $revision['version_number'] }}</h2><p>وقت الحفظ: <bdi>{{ $revision['created_at'] }}</bdi></p>
<p>الكاتب: <bdi>{{ $revision['author_public_id'] ?? 'حساب تاريخي' }}</bdi></p><pre>{{ $revision['body'] }}</pre></article>
@empty<p>لم يُحفظ محتوى بعد.</p>@endforelse
@if($operational ?? false)<nav aria-label="صفحات المسودات">
@if($revisions->currentPage()>1)<a href="{{ route('institution.approaches.content',['organization'=>$organization,'approach'=>$resource,'page'=>$revisions->currentPage()-1],false) }}">المسودات الأحدث</a>@endif
@if($revisions->hasMorePages())<a href="{{ route('institution.approaches.content',['organization'=>$organization,'approach'=>$resource,'page'=>$revisions->currentPage()+1],false) }}">المسودات الأقدم</a>@endif
</nav>@endif
</main></body></html>
