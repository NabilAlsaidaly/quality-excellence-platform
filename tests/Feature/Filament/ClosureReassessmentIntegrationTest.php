<?php

namespace Tests\Feature\Filament;

use App\Filament\Institution\Resources\ReviewMaturityResource\Pages\MaturityWorkspace;
use DOMDocument;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Feature\Maturity\ClosureReassessmentWorkflowTest;
use Tests\Support\ReviewMaturityPanelProvider;

class ClosureReassessmentIntegrationTest extends ClosureReassessmentWorkflowTest
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->instance('request', Request::create('http://localhost'));
        $app->booting(fn () => $app->register(ReviewMaturityPanelProvider::class));
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    private function snapshot(TestResponse $response): string
    {
        $dom = new DOMDocument;
        $old = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        foreach ($dom->getElementsByTagName('*') as $node) {
            if ($node->hasAttribute('wire:snapshot')) {
                $snapshot = $node->getAttribute('wire:snapshot');
                $name = json_decode($snapshot, true)['memo']['name'];
                if ($name === MaturityWorkspace::class || str_contains($name, 'maturity-workspace')) {
                    return $snapshot;
                }
            }
        }
        $this->fail('Actual native maturity snapshot required.');
    }

    private function transport(string &$snapshot, string $method, array $params = [], array $updates = [], int $status = 200): TestResponse
    {
        $response = $this->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => $updates,
            'calls' => [['path' => '', 'method' => $method, 'params' => $params]]]]], ['X-Livewire' => 'true', 'X-CSRF-TOKEN' => session()->token()])->assertStatus($status);
        if ($status === 200) {
            $snapshot = $response->json('components.0.snapshot');
            $this->assertIsString($snapshot);
        }

        return $response;
    }

    public function test_native_signed_http_opens_saves_and_independently_approves_reassessment_with_history(): void
    {
        $f = $this->completeCycle();
        $stage = $f['stages'][0];
        $original = $this->state($f)['stages'][$stage->stage];
        $question = $original['configuration']['questions'][0]['public_id'];
        config(['app.url' => 'http://localhost', 'app.debug' => false]);
        URL::forceRootUrl('http://localhost');
        $url = '/institution/mvp-workspace/workflows/'.$f['approach']->public_id.'/maturity/'.$stage->public_id;
        ReviewMaturityPanelProvider::withFixture($this->workflowPanelProof($f), function () use ($f, $url, $question): void {
            Auth::forgetGuards();
            $this->post('/institution/login', ['organization' => $f['organization']->public_id, 'email' => $f['author']->email, 'password' => 'password'])->assertRedirect();
            $page = $this->get($url)->assertOk()->assertSee('طلب إعادة تقييم')->assertSee('سجل إعادة التقييم والمقارنة')->assertDontSee('تحرير إجابات المرحلة');
            $snapshot = $this->snapshot($page);
            $this->transport($snapshot, 'mountAction', ['openReassessment']);
            $this->transport($snapshot, 'callMountedAction');
            $this->assertDatabaseCount('maturity_reassessment_attempts', 1);
            $this->transport($snapshot, 'mountAction', ['saveReassessment']);
            $this->transport($snapshot, 'callMountedAction', updates: ['mountedActions.0.data.answers.'.$question => 'إجابة عبر النموذج الأصلي لإعادة التقييم']);
            $this->assertDatabaseCount('maturity_workflow_submissions', 6);
            $this->assertDatabaseCount('review_maturity_notifications', 11);
            $this->post('/institution/'.$f['organization']->public_id.'/logout')->assertRedirect();
            $this->post('/institution/login', ['organization' => $f['organization']->public_id, 'email' => $f['reviewer']->email, 'password' => 'password'])->assertRedirect();
            $page = $this->get($url)->assertOk()->assertSee('مراجعة إعادة التقييم')->assertDontSee('تحرير إعادة التقييم');
            $snapshot = $this->snapshot($page);
            $this->transport($snapshot, 'mountAction', ['reviewReassessment']);
            $this->transport($snapshot, 'callMountedAction', updates: ['mountedActions.0.data.decision' => 'approved',
                'mountedActions.0.data.band_index' => '0', 'mountedActions.0.data.reason' => 'مراجعة مستقلة عبر النموذج الأصلي.']);
            $this->assertDatabaseCount('maturity_reassessment_decisions', 1);
            $this->assertDatabaseCount('maturity_workflow_assessments', 5);
            $this->assertDatabaseCount('review_maturity_notifications', 12);
            $this->transport($snapshot, 'mountAction', ['reassessmentHistory']);
            $this->get($url)->assertOk()->assertSee('التصنيف الفعّال حاليًا')->assertSee('بداية');
        });
    }
}
