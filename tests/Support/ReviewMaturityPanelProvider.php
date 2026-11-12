<?php

namespace Tests\Support;

use App\Actions\Reviews\ReviewMaturityWorkspace;
use App\Authorization\ApproachAssignmentAccess;
use App\Authorization\DocumentWorkspaceAccess;
use App\Authorization\ReviewMaturityAccess;
use App\Authorization\TenantContext;
use App\Filament\Institution\Pages\OrganizationWorkspace;
use App\Filament\Institution\Pages\WorkflowNotifications;
use App\Filament\Institution\Resources\ApproachResource;
use App\Filament\Institution\Resources\DocumentResource;
use App\Filament\Institution\Resources\ReviewMaturityResource;
use App\Http\Controllers\Institution\DemoDocumentVersionDownloadController;
use App\Models\Organization;
use App\Models\ReviewMaturityReview;
use App\Models\User;
use App\Policies\ReviewMaturityPolicy;
use Closure;
use Filament\Facades\Filament;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use WeakMap;

/** Explicitly registered only by the owned test application, never normal providers. */
final class ReviewMaturityPanelProvider extends PanelProvider
{
    private static ?WeakMap $applications = null;

    public static function withFixture(array $proof, Closure $operation): mixed
    {
        self::$applications ??= new WeakMap;
        if (! app()->environment('testing') || isset(self::$applications[app()])) {
            throw new LogicException('Explicit non-nested owned test application required.');
        }
        self::$applications[app()] = $proof;
        try {
            return $operation();
        } finally {
            unset(self::$applications[app()]);
        }
    }

    public static function proof(): ?array
    {
        return self::$applications[app()] ?? null;
    }

    public function register(): void
    {
        if (! $this->app->environment('testing')) {
            return;
        }
        parent::register();
        config(['livewire.temporary_file_upload.middleware' => ['throttle:60,1', ClosureDocumentUploadAuthorization::class]]);
        // Livewire selects this disk by name whenever APP_ENV=testing,
        // including a real browser harness. Reuse its owned private local root.
        config(['filesystems.disks.tmp-for-tests' => array_replace(config('filesystems.disks.local'), ['serve' => false, 'visibility' => 'private'])]);
        Gate::policy(ReviewMaturityReview::class, ReviewMaturityPolicy::class);
        // HttpKernel may already be resolved when this test provider boots.
        // Laravel's helper also applies the callback to that existing instance.
        $this->callAfterResolving(Kernel::class, fn ($kernel) => $kernel->prependMiddleware(ReviewMaturityFixtureRequestScope::class));
    }

    public function panel(Panel $panel): Panel
    {
        return $panel->id('mvp_workflow_fixture')->path('institution/mvp-workspace')->authGuard('institution')
            ->brandName('الجودة والتميز — عرض اختباري')->viteTheme('resources/css/filament/admin/theme.css')
            ->colors(['primary' => Color::hex('#17324D')])->resources([ApproachResource::class, ReviewMaturityResource::class, DocumentResource::class])->pages([WorkflowNotifications::class, OrganizationWorkspace::class])->widgets([])->userMenu(false)
            ->middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class,
                ShareErrorsFromSession::class, PreventRequestForgery::class, SubstituteBindings::class,
                DisableBladeIconComponents::class, DispatchServingFilamentEvent::class])
            ->authMiddleware([ReviewMaturityFixtureAuthenticate::class], isPersistent: true);
    }

    public function boot(): void
    {
        if (app()->environment('testing')) {
            $this->app->booted(function (): void {
                foreach (Route::getRoutes() as $route) {
                    if (in_array('POST', $route->methods(), true) && str_contains($route->getName() ?? '', 'livewire') && str_contains($route->uri(), 'update')) {
                        // Persistent middleware executes a verification-only
                        // pipeline. Temporary-file hydration needs context for
                        // the actual entire update controller invocation.
                        $route->middleware(ClosureDocumentUploadContext::class);
                    }
                }
            });
            Route::get('/institution/local-document-versions/{document}/{version}/download', DemoDocumentVersionDownloadController::class)
                ->middleware('web')->name('institution.local-document-version.download');
        }
        Gate::before(function (?User $actor, string $ability, array $arguments): ?bool {
            if ($ability !== 'tenant-capability' || ! in_array($arguments[0] ?? null, ['organization.units.view', 'organization.units.manage'], true)) {
                return null;
            }
            $units = self::proof()['organization_units'] ?? null;
            if ($units === null) {
                return null;
            }

            // Denial-only fixture restriction, evaluated after real session
            // middleware and again by the actual Gate under the write lock.
            // Null delegates to the unchanged real role/permission decision.
            return $actor !== null && ReviewMaturityAccess::context() !== null
                && in_array((int) $actor->getKey(), $units['actor_ids'] ?? [], true) ? null : false;
        });
        View::composer('institution.account', function ($view): void {
            // Only the explicit final-integration fixture supplies this link.
            // Admission is the same current real Policy used by its panel.
            if (app()->environment('testing') && (self::proof()['organization_units'] ?? null) !== null
                && ReviewMaturityAccess::context() !== null && app(ReviewMaturityWorkspace::class)->canEnter()) {
                $view->with('localDemoWorkspaceUrl', '/institution/mvp-workspace/workflows');
            }
        });
        app(ExceptionHandler::class)->renderable(function (HttpExceptionInterface|CannotUpdateLockedPropertyException $error, Request $request) {
            if (Filament::getCurrentPanel()?->getId() !== 'mvp_workflow_fixture') {
                return null;
            }
            $status = $error instanceof CannotUpdateLockedPropertyException ? 403 : $error->getStatusCode();
            $message = match ($status) {
                403 => 'الوصول غير مسموح.', 404 => 'غير متاح.', 409 => 'تغيرت بيانات الوثيقة؛ أعد فتح النسخة الحالية.', default => null,
            };
            if ($message === null) {
                return null;
            }

            return $request->expectsJson() ? response()->json(['message' => $message], $status, ['Cache-Control' => 'no-store, private'])
                : response($message, $status, ['Cache-Control' => 'no-store, private']);
        });
    }
}

/** Kernel-wide in this test app so signed Livewire updates keep the exact real Request proof. */
final class ReviewMaturityFixtureRequestScope
{
    private function withUnits(array $proof, Closure $next): mixed
    {
        $units = $proof['organization_units'] ?? null;
        if ($units === null) {
            return $next();
        }
        abort_unless(($units['scenario'] ?? null) === 'UNIT_LOCAL_001'
            && ($units['fixture_id'] ?? null) === $proof['workflow']['fixture_id']
            && ($units['organization_id'] ?? null) === $proof['workflow']['organization_id']
            && is_array($units['actor_ids'] ?? null), 403);
        // Provisional Issue50 mapping only in this owned request. The actual
        // TenantAuthorization role+permission decision remains mandatory.
        $old = config('authorization.capabilities');
        config(['authorization.capabilities' => array_merge($old, [
            'organization.units.view' => Organization::class, 'organization.units.manage' => Organization::class,
        ])]);
        try {
            return $next();
        } finally {
            config(['authorization.capabilities' => $old]);
        }
    }

    public function handle(Request $request, Closure $next)
    {
        $proof = ReviewMaturityPanelProvider::proof();
        if ($proof !== null && ! app()->environment('testing')) {
            return response('غير متاح.', 404, ['Cache-Control' => 'no-store, private']);
        }

        return $proof === null ? $next($request) : ApproachAssignmentAccess::runFixture($request, $proof['approach'], fn () => DocumentWorkspaceAccess::runFixture($request, $proof['document'], fn () => ReviewMaturityAccess::runFixture($request, $proof['workflow'], fn () => $this->withUnits($proof, fn () => $next($request)))));
    }
}

/** Real institutional identity + current Workflow Policy; neither shared panel admission nor synthetic users. */
final class ReviewMaturityFixtureAuthenticate
{
    public function handle(Request $request, Closure $next)
    {
        $headers = ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff'];
        abort_unless(app()->environment('testing') && ReviewMaturityAccess::context() !== null
            && Filament::getCurrentPanel()?->getId() === 'mvp_workflow_fixture', 404);
        abort_unless(strtolower($request->getHost()) === strtolower((string) parse_url(config('app.url'), PHP_URL_HOST))
            && in_array($request->ip(), ['127.0.0.1', '127.0.0.6'], true), 403);
        $guard = Auth::guard('institution');
        if (! $guard->check()) {
            return redirect('/institution/login')->withHeaders($headers);
        }
        $original = Auth::getDefaultDriver();
        $tenant = app(TenantContext::class);
        $tenant->clear();
        try {
            // canEnter() reloads the actual InstitutionUserProvider, validates the active
            // guard/tenant/password/membership and invokes the real Workflow Policy in SQL.
            abort_unless(app(ReviewMaturityWorkspace::class)->canEnter(), 403);
            Auth::shouldUse('institution');
            $response = $next($request);
            $response->headers->add($headers);

            return $response;
        } finally {
            $tenant->clear();
            Auth::shouldUse($original);
        }
    }
}
