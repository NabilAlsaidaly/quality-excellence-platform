<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>بنك المنهجيات</title>
<style>body{font-family:Tahoma,Arial,sans-serif;background:#f5f8fa;color:#182f3b;line-height:1.9;margin:0;overflow-wrap:anywhere}main{max-width:68rem;margin:auto;padding:clamp(1rem,3vw,2.5rem)}article,section,form,dl{background:white;border:1px solid #ccdce3;border-radius:.7rem;padding:1rem;margin-block:1rem}h1{line-height:1.5}label{display:block;font-weight:bold;margin-top:.8rem}input,textarea,select,button{font:inherit;box-sizing:border-box;max-width:100%}input:not([type=checkbox]),textarea,select{width:100%;border:1px solid #6f8995;border-radius:.35rem;padding:.5rem}textarea{min-height:7rem}button{background:#075e63;color:white;border:0;border-radius:.35rem;padding:.5rem 1rem;margin-block:.8rem;cursor:pointer}a{color:#075e63;text-underline-offset:.2rem}nav{display:flex;flex-wrap:wrap;gap:1rem}dt{font-weight:bold}dd{margin:0 0 .8rem;white-space:pre-wrap}pre{white-space:pre-wrap;overflow-wrap:anywhere;font:inherit}[role=alert]{color:#8d2020;border-inline-start:4px solid;padding:.7rem}[role=status]{background:#e7f2f2;padding:.8rem;border-radius:.4rem}:focus-visible{outline:3px solid #b97400;outline-offset:3px}.hint{color:#425d6b}.actions{display:flex;gap:1rem;flex-wrap:wrap}</style></head>
<body><main>
<h1>بنك المنهجيات</h1>
<p role="status">{{ ($operational ?? false) ? __('institution_approaches.draft_notice', [], 'ar') : 'قالب داخلي غير مفعّل — ينتظر اعتماد الصلاحيات والتفعيل المستقل.' }}</p>
@if (session('status'))<p role="status">{{ session('status') }}</p>@endif
@if (isset($errors) && $errors->any())<div role="alert"><p>تعذّر الحفظ. راجع الحقول التالية:</p><ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif

@if (!empty($error))<p role="alert">{{ $error }}</p>@endif
@if ($operational ?? false)
<nav>@if($can['create'])<a href="{{ route('institution.approaches.create', ['organization'=>$organization], false) }}">إنشاء منهجية</a>@endif</nav>
<form method="get" action="{{ route('institution.approaches.index', ['organization'=>$organization], false) }}">
<label for="search">البحث بالاسم</label><input id="search" name="search" value="{{ $search }}" maxlength="255">
<label for="sort">الترتيب حسب</label><select id="sort" name="sort"><option value="created_at" @selected($sort==='created_at')>وقت الإنشاء</option><option value="name" @selected($sort==='name')>الاسم</option></select>
<label for="direction">اتجاه الترتيب</label><select id="direction" name="direction"><option value="desc" @selected($direction==='desc')>تنازلي</option><option value="asc" @selected($direction==='asc')>تصاعدي</option></select>
<button type="submit">بحث</button></form>
@endif
<section aria-label="المنهجيات">
@forelse ($approaches as $approach)
<article><h2>@if(($operational ?? false) && $approach['can_view'])<a href="{{ route('institution.approaches.show',['organization'=>$organization,'approach'=>$approach['public_id']],false) }}">{{ $approach['name'] }}</a>@else{{ $approach['name'] }}@endif</h2>
<p>{{ $approach['description'] }}</p><p>الوحدة المالكة: {{ $approach['owning_unit']['name'] }}</p><p>المعرّف: <bdi>{{ $approach['public_id'] }}</bdi></p></article>
@empty<p>لا توجد منهجيات ضمن نطاق القراءة.</p>@endforelse
</section>
<p>النتائج: {{ $approaches->total() }} — الصفحة {{ $approaches->currentPage() }} من {{ $approaches->lastPage() }}</p>
@if ($operational ?? false)<nav aria-label="صفحات المنهجيات">
@foreach (['السابق'=>$approaches->currentPage()-1,'التالي'=>$approaches->currentPage()+1] as $label=>$page)
@if($page >= 1 && $page <= $approaches->lastPage() && $page !== $approaches->currentPage())<a href="{{ route('institution.approaches.index',['organization'=>$organization,'page'=>$page,'search'=>$search,'sort'=>$sort,'direction'=>$direction],false) }}">{{ $label }}</a>@endif
@endforeach</nav>@endif
</main></body></html>
