<!doctype html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>إنشاء منهجية</title>
    <style>
        body {
            font-family: Tahoma, Arial, sans-serif;
            background: #f5f8fa;
            color: #182f3b;
            line-height: 1.9;
            margin: 0;
            overflow-wrap: anywhere
        }

        main {
            max-width: 68rem;
            margin: auto;
            padding: clamp(1rem, 3vw, 2.5rem)
        }

        article,
        section,
        form,
        dl {
            background: white;
            border: 1px solid #ccdce3;
            border-radius: .7rem;
            padding: 1rem;
            margin-block: 1rem
        }

        h1 {
            line-height: 1.5
        }

        label {
            display: block;
            font-weight: bold;
            margin-top: .8rem
        }

        input,
        textarea,
        select,
        button {
            font: inherit;
            box-sizing: border-box;
            max-width: 100%
        }

        input:not([type=checkbox]),
        textarea,
        select {
            width: 100%;
            border: 1px solid #6f8995;
            border-radius: .35rem;
            padding: .5rem
        }

        textarea {
            min-height: 7rem
        }

        button {
            background: #075e63;
            color: white;
            border: 0;
            border-radius: .35rem;
            padding: .5rem 1rem;
            margin-block: .8rem;
            cursor: pointer
        }

        a {
            color: #075e63;
            text-underline-offset: .2rem
        }

        nav {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem
        }

        dt {
            font-weight: bold
        }

        dd {
            margin: 0 0 .8rem;
            white-space: pre-wrap
        }

        pre {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            font: inherit
        }

        [role=alert] {
            color: #8d2020;
            border-inline-start: 4px solid;
            padding: .7rem
        }

        [role=status] {
            background: #e7f2f2;
            padding: .8rem;
            border-radius: .4rem
        }

        :focus-visible {
            outline: 3px solid #b97400;
            outline-offset: 3px
        }

        .hint {
            color: #425d6b
        }

        .actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap
        }
    </style>
</head>

<body>
    <main>
        <h1>إنشاء منهجية</h1>
        <p role="status">
            {{ $operational ?? false ? __('institution_approaches.draft_notice', [], 'ar') : 'قالب داخلي غير مفعّل — ينتظر اعتماد الصلاحيات والتفعيل المستقل.' }}
        </p>
        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif
        @if (isset($errors) && $errors->any())
            <div role="alert">
                <p>تعذّر الحفظ. راجع الحقول التالية:</p>
                <ul>
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if ($operational ?? false)
            <form method="post"
                action="{{ route('institution.approaches.store', ['organization' => $organization], false) }}">@csrf
                @foreach (['name', 'description', 'purpose', 'scope', 'objectives', 'review_frequency'] as $field)
                    <label
                        for="{{ $field }}">{{ __('institution_approaches.fields.' . $field, [], 'ar') }}{{ in_array($field, ['objectives', 'review_frequency']) ? ' (اختياري)' : ' *' }}</label>
                    @if ($field === 'name')
                        <input id="{{ $field }}" name="{{ $field }}"
                            value="{{ old($field, $approach[$field] ?? '') }}" maxlength="255" required>
                    @else
                        <textarea id="{{ $field }}" name="{{ $field }}" maxlength="65535" @required(!in_array($field, ['objectives', 'review_frequency']))>{{ old($field, $approach[$field] ?? '') }}</textarea>
                    @endif
                @endforeach
                <label for="owning_unit_public_id">الوحدة المالكة الرئيسية *</label>
                <select id="owning_unit_public_id" name="owning_unit_public_id" required>
                    <option value="">اختر الوحدة</option>
                    @foreach ($owners as $owner)
                        <option value="{{ $owner['public_id'] }}" @selected(old('owning_unit_public_id', $approach['owning_unit']['public_id'] ?? '') === $owner['public_id'])>{{ $owner['name'] }}
                        </option>
                    @endforeach
                </select>
                <p class="hint">تُعرض 100 وحدة في الصفحة. لا يغيّر نقل الملكية صلاحيات المستخدمين أو محتوى المسودات.
                </p>
                <nav aria-label="صفحات الوحدات">
                    @if ($owners->currentPage() > 1)
                        <a
                            href="{{ route(isset($approach) ? 'institution.approaches.owner' : 'institution.approaches.create', ['organization' => $organization, 'page' => $owners->currentPage() - 1] + (isset($approach) ? ['approach' => $resource] : []), false) }}">الوحدات
                            السابقة</a>
                    @endif
                    @if ($owners->hasMorePages())
                        <a
                            href="{{ route(isset($approach) ? 'institution.approaches.owner' : 'institution.approaches.create', ['organization' => $organization, 'page' => $owners->currentPage() + 1] + (isset($approach) ? ['approach' => $resource] : []), false) }}">وحدات
                            تالية</a>
                    @endif
                </nav>
                <button type="submit">حفظ المنهجية</button>
            </form>
        @endif
    </main>
</body>

</html>
