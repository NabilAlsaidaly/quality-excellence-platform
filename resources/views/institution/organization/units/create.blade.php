<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('institution_organization.units.create_title') }}</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 2rem 1rem;
            color: #1f2937;
            background: #f8fafc;
            font-family: Tahoma, Arial, sans-serif;
            line-height: 1.8;
        }

        main {
            width: 100%;
            max-width: 48rem;
            margin: 0 auto;
        }

        .page-header {
            margin-bottom: 1.5rem;
        }

        h1 {
            margin: 0;
            font-size: 1.7rem;
        }

        .panel {
            padding: 1.5rem;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            background: #ffffff;
        }

        .field {
            margin-bottom: 1.25rem;
        }

        label {
            display: block;
            margin-bottom: 0.4rem;
            font-weight: 600;
        }

        input,
        select {
            width: 100%;
            min-height: 46px;
            padding: 0.6rem 0.75rem;
            border: 1px solid #cbd5e1;
            border-radius: 0.45rem;
            color: #1f2937;
            background: #ffffff;
            font: inherit;
        }

        input:focus,
        select:focus {
            border-color: #2563eb;
            outline: 3px solid #bfdbfe;
            outline-offset: 1px;
        }

        .field-error {
            margin: 0.35rem 0 0;
            color: #b91c1c;
            font-size: 0.9rem;
        }

        .feedback-error {
            margin-bottom: 1.25rem;
            padding: 0.75rem 1rem;
            border: 1px solid #fecaca;
            border-radius: 0.5rem;
            color: #991b1b;
            background: #fef2f2;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }

        .button {
            display: inline-flex;
            min-height: 44px;
            align-items: center;
            justify-content: center;
            padding: 0.55rem 1rem;
            border: 1px solid #1d4ed8;
            border-radius: 0.5rem;
            color: #ffffff;
            background: #1d4ed8;
            font: inherit;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .button-secondary {
            border-color: #cbd5e1;
            color: #334155;
            background: #ffffff;
        }

        a:focus-visible,
        button:focus-visible {
            outline: 3px solid #93c5fd;
            outline-offset: 3px;
        }

        @media (max-width: 480px) {
            body {
                padding: 1rem 0.75rem;
            }

            .panel {
                padding: 1rem;
            }

            .actions,
            .button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <main>
        <header class="page-header">
            <h1>{{ __('institution_organization.units.create_title') }}</h1>
        </header>

        @if ($errors->any())
            <div class="feedback-error" role="alert">
                <p>{{ __('institution_organization.validation.errors_title') }}</p>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="panel">
            <form
                method="post"
                action="{{ route('institution.units.store', ['organization' => $organizationPublicId]) }}"
            >
                @csrf

                <div class="field">
                    <label for="name">
                        {{ __('institution_organization.units.name') }}
                    </label>

                    <input
                        id="name"
                        name="name"
                        type="text"
                        value="{{ old('name') }}"
                        maxlength="255"
                        required
                        autocomplete="off"
                        aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}"
                        @if ($errors->has('name')) aria-describedby="name-error" @endif
                    >

                    @error('name')
                        <p class="field-error" id="name-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="parent_public_id">
                        {{ __('institution_organization.units.parent') }}
                    </label>

                    <select
                        id="parent_public_id"
                        name="parent_public_id"
                        aria-invalid="{{ $errors->has('parent_public_id') ? 'true' : 'false' }}"
                        @if ($errors->has('parent_public_id')) aria-describedby="parent-error" @endif
                    >
                        <option value="">
                            {{ __('institution_organization.units.no_parent') }}
                        </option>

                        @foreach ($tree['nodes'] as $unitPublicId => $unit)
                            <option
                                value="{{ $unitPublicId }}"
                                @selected(old('parent_public_id') === $unitPublicId)
                            >
                                {{ $unit['name'] }}
                            </option>
                        @endforeach
                    </select>

                    @error('parent_public_id')
                        <p class="field-error" id="parent-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="actions">
                    <button class="button" type="submit">
                        {{ __('institution_organization.actions.create_unit') }}
                    </button>

                    <a
                        class="button button-secondary"
                        href="{{ route('institution.units.index', ['organization' => $organizationPublicId]) }}"
                    >
                        {{ __('institution_organization.actions.cancel') }}
                    </a>
                </div>
            </form>
        </section>
    </main>
</body>
</html>