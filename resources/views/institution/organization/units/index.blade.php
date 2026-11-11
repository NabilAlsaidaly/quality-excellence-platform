@if (isset($renderNode) && $renderNode)
    <li class="tree-node">
        <div class="tree-node-content">
            <span class="tree-node-name">{{ $nodes[$nodePublicId]['name'] }}</span>

            <a
                class="unit-action"
                href="{{ route('institution.units.edit', ['organization' => $organizationPublicId, 'unit' => $nodePublicId]) }}"
            >
                {{ __('institution_organization.units.edit_title') }}
            </a>
        </div>

        @if ($nodes[$nodePublicId]['children'] !== [])
            <ul class="tree-children">
                @foreach ($nodes[$nodePublicId]['children'] as $childPublicId)
                    @include('institution.organization.units.index', [
                        'renderNode' => true,
                        'nodePublicId' => $childPublicId,
                        'nodes' => $nodes,
                        'organizationPublicId' => $organizationPublicId,
                    ])
                @endforeach
            </ul>
        @endif
    </li>
@else
    <!doctype html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="referrer" content="no-referrer">
        <title>{{ __('institution_organization.units.title') }}</title>
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
                max-width: 64rem;
                margin: 0 auto;
            }

            .page-header {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                gap: 1rem;
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

            .tree-list,
            .tree-children {
                padding: 0;
                margin: 0;
                list-style: none;
            }

            .tree-node + .tree-node {
                margin-top: 0.75rem;
            }

            .tree-node-content {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                gap: 0.75rem;
                min-height: 3rem;
                padding: 0.75rem 1rem;
                border: 1px solid #e2e8f0;
                border-radius: 0.5rem;
                background: #ffffff;
            }

            .tree-node-name {
                font-weight: 600;
                overflow-wrap: anywhere;
            }

            .tree-children {
                margin: 0.75rem 1.5rem 0 0;
                padding-right: 1rem;
                border-right: 2px solid #cbd5e1;
            }

            .unit-action {
                display: inline-flex;
                min-height: 40px;
                align-items: center;
                padding: 0.35rem 0.75rem;
                border-radius: 0.4rem;
                color: #1d4ed8;
                background: #eff6ff;
                font-size: 0.9rem;
                font-weight: 600;
                text-decoration: none;
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

            a:focus-visible {
                outline: 3px solid #93c5fd;
                outline-offset: 3px;
            }

            .empty-state {
                padding: 2rem 1rem;
                color: #64748b;
                text-align: center;
            }

            .empty-state h2 {
                margin: 0 0 0.5rem;
                color: #334155;
                font-size: 1.2rem;
            }

            .feedback {
                margin-bottom: 1rem;
                padding: 0.75rem 1rem;
                border: 1px solid #e2e8f0;
                border-radius: 0.5rem;
                background: #ffffff;
            }

            .feedback-error {
                border-color: #fecaca;
                color: #991b1b;
                background: #fef2f2;
            }

            @media (max-width: 480px) {
                body {
                    padding: 1rem 0.75rem;
                }

                .panel {
                    padding: 1rem;
                }

                .page-header,
                .page-header .button {
                    width: 100%;
                }

                .tree-node-content {
                    align-items: flex-start;
                    flex-direction: column;
                }

                .tree-children {
                    margin-right: 0.75rem;
                    padding-right: 0.75rem;
                }
            }
        </style>
    </head>
    <body>
        <main>
            <header class="page-header">
                <h1>{{ __('institution_organization.units.title') }}</h1>

                <a
                    class="button"
                    href="{{ route('institution.units.create', ['organization' => $organizationPublicId]) }}"
                >
                    {{ __('institution_organization.actions.create_unit') }}
                </a>
            </header>

            @if (session('status'))
                <p class="feedback" role="status">{{ session('status') }}</p>
            @endif

            @if ($errors->any())
                <div class="feedback feedback-error" role="alert">
                    <p>{{ __('institution_organization.validation.errors_title') }}</p>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <section class="panel" aria-label="{{ __('institution_organization.units.title') }}">
                @if ($tree['roots'] === [])
                    <div class="empty-state">
                        <h2>{{ __('institution_organization.units.empty_title') }}</h2>
                        <p>{{ __('institution_organization.units.empty_description') }}</p>
                    </div>
                @else
                    <ul class="tree-list">
                        @foreach ($tree['roots'] as $rootPublicId)
                            @include('institution.organization.units.index', [
                                'renderNode' => true,
                                'nodePublicId' => $rootPublicId,
                                'nodes' => $tree['nodes'],
                                'organizationPublicId' => $organizationPublicId,
                            ])
                        @endforeach
                    </ul>
                @endif
            </section>
        </main>
    </body>
    </html>
@endif