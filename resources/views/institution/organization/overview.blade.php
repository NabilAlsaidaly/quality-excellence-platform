<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('institution_organization.overview.title') }}</title>
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
            max-width: 56rem;
            margin: 0 auto;
        }

        .page-header {
            margin-bottom: 1.5rem;
        }

        h1 {
            margin: 0 0 0.5rem;
            font-size: 1.75rem;
        }

        .page-description {
            margin: 0;
            color: #64748b;
        }

        .panel {
            padding: 1.5rem;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            background: #ffffff;
        }

        h2 {
            margin: 0 0 1.25rem;
            font-size: 1.2rem;
        }

        .metadata-list {
            display: grid;
            gap: 1rem;
            margin: 0;
        }

        .metadata-item {
            padding-bottom: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .metadata-item:last-child {
            padding-bottom: 0;
            border-bottom: 0;
        }

        dt {
            margin-bottom: 0.25rem;
            color: #64748b;
            font-size: 0.9rem;
        }

        dd {
            margin: 0;
            overflow-wrap: anywhere;
            font-weight: 600;
        }

        .public-identifier {
            direction: ltr;
            text-align: right;
            font-family: Consolas, monospace;
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
            font-weight: 600;
            text-decoration: none;
        }

        .button:focus-visible {
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
            <h1>{{ __('institution_organization.overview.title') }}</h1>
            <p class="page-description">
                {{ __('institution_organization.overview.institution_details') }}
            </p>
        </header>

        <section class="panel" aria-labelledby="institution-details-heading">
            <h2 id="institution-details-heading">
                {{ __('institution_organization.overview.institution_details') }}
            </h2>

            <dl class="metadata-list">
                <div class="metadata-item">
                    <dt>{{ __('institution_organization.overview.institution_name') }}</dt>
                    <dd>{{ $metadata['name'] }}</dd>
                </div>

                <div class="metadata-item">
                    <dt>{{ __('institution_organization.overview.public_identifier') }}</dt>
                    <dd class="public-identifier" dir="ltr">
                        {{ $metadata['public_id'] }}
                    </dd>
                </div>
            </dl>

            <div class="actions">
                <a
                    class="button"
                    href="{{ route('institution.units.index', ['organization' => $metadata['public_id']]) }}"
                >
                    {{ __('institution_organization.actions.view_units') }}
                </a>
            </div>
        </section>
    </main>
</body>
</html>