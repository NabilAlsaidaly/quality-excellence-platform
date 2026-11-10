<?php

namespace Tests\Feature\Filament;

use App\Filament\Institution\Resources\ReviewMaturityResource\Pages\ContentReviewWorkspace;
use App\Filament\Institution\Resources\ReviewMaturityResource\Pages\MaturityWorkspace;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\ReviewMaturityFixtures;
use Tests\Support\ReviewMaturityPanelProvider;
use Tests\TestCase;

class ReviewMaturityIntegrationTest extends TestCase
{
    use DatabaseMigrations, ReviewMaturityFixtures;

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->instance('request', Request::create('http://localhost'));
        $app->booting(fn () => $app->register(ReviewMaturityPanelProvider::class));
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function tearDown(): void
    {
        $this->cleanupReviewMaturity();
        parent::tearDown();
    }

    public function test_original_login_and_actual_policy_admit_author_and_independent_reviewer_to_one_native_panel(): void
    {
        $f = $this->reviewMaturityFixture();
        config(['app.url' => 'http://localhost', 'app.debug' => false]);
        URL::forceRootUrl('http://localhost');
        ReviewMaturityPanelProvider::withFixture($this->workflowPanelProof($f), function () use ($f): void {
            foreach (['author', 'reviewer'] as $name) {
                Auth::forgetGuards();
                $this->post('/institution/login', ['organization' => $f['organization']->public_id, 'email' => $f[$name]->email, 'password' => 'password'])
                    ->assertRedirect('/institution/'.$f['organization']->public_id.'/account');
                $this->assertInstanceOf(User::class, Auth::guard('institution')->user());
                $this->assertFalse($f[$name]->canAccessPanel(Filament::getPanel('mvp_workflow_fixture')));
                $this->get('/institution/mvp-workspace/workflows')->assertOk()->assertSee('المراجعة والنضج');
                $this->get('/institution/mvp-workspace/workflows/'.$f['approach']->public_id)->assertOk()->assertSee('المحتوى والمراجعة');
                $this->post(route('institution.logout', ['organization' => $f['organization']->public_id], false))->assertRedirect();
            }
        });
    }

    private function snapshot(TestResponse $response, string $class): string
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $name = str($class)->replace('\\', '.')->explode('.')->map(fn ($part) => (string) str($part)->kebab())->implode('.');
        foreach ($document->getElementsByTagName('*') as $node) {
            if ($node->hasAttribute('wire:snapshot')) {
                $snapshot = $node->getAttribute('wire:snapshot');
                if (in_array(json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['memo']['name'], [$name, $class], true)) {
                    return $snapshot;
                }
            }
        }
        $this->fail('Actual native workflow snapshot missing.');
    }

    private function transport(string &$snapshot, string $method, array $parameters = [], array $updates = []): TestResponse
    {
        $response = $this->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => $updates,
            'calls' => [['path' => '', 'method' => $method, 'params' => $parameters]]]]], ['X-Livewire' => 'true', 'X-CSRF-TOKEN' => session()->token()]);
        $response->assertOk();
        $snapshot = $response->json('components.0.snapshot');
        $this->assertIsString($snapshot);

        return $response;
    }

    public function test_signed_native_livewire_save_and_configured_repeater_use_current_real_policy(): void
    {
        $f = $this->reviewMaturityFixture();
        config(['app.url' => 'http://localhost', 'app.debug' => false]);
        URL::forceRootUrl('http://localhost');
        ReviewMaturityPanelProvider::withFixture($this->workflowPanelProof($f), function () use ($f): void {
            $this->post('/institution/login', ['organization' => $f['organization']->public_id, 'email' => $f['author']->email, 'password' => 'password'])->assertRedirect();
            $path = '/institution/mvp-workspace/workflows/'.$f['approach']->public_id;
            $response = $this->get($path)->assertOk();
            $snapshot = $this->snapshot($response, ContentReviewWorkspace::class);
            $this->transport($snapshot, 'mountAction', ['editContent']);
            $this->transport($snapshot, 'callMountedAction', updates: ['mountedActions.0.data.body' => 'حفظ فعلي عبر Livewire الموقّع']);
            $this->assertSame(1, DB::table('review_maturity_reviews')->count());
            $this->assertSame(1, DB::table('review_maturity_notifications')->count());
            $this->assertSame('حفظ فعلي عبر Livewire الموقّع', DB::table('approach_content_revisions')->value('body'));
            $this->post(route('institution.logout', ['organization' => $f['organization']->public_id], false))->assertRedirect();
            Auth::forgetGuards();
            $this->post('/institution/login', ['organization' => $f['organization']->public_id, 'email' => $f['reviewer']->email, 'password' => 'password'])->assertRedirect();
            $stage = DB::table('maturity_workflow_stages')->where('fixture_id', $f['proof']['fixture_id'])->orderBy('stage_ordinal')->first();
            $response = $this->get($path.'/maturity/'.$stage->public_id)->assertOk();
            $snapshot = $this->snapshot($response, MaturityWorkspace::class);
            $this->transport($snapshot, 'mountAction', ['configure']);
            $this->transport($snapshot, 'mountAction', ['add', [], ['schemaComponent' => 'mountedActionSchema0.questions']]);
            $data = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['data'];
            $this->assertStringContainsString('expected_evidence', json_encode($data));
            $this->assertSame(0, DB::table('maturity_workflow_configurations')->count(), 'Repeater edits remain form state until the actual authorized save.');
        });
    }
}
