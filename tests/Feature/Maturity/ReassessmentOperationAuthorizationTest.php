<?php

namespace Tests\Feature\Maturity;

use App\Actions\Maturity\ManageStageReassessmentOperations;
use App\Authorization\ReviewMaturityAccess;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReviewMaturityFixtures;
use Tests\TestCase;

class ReassessmentOperationAuthorizationTest extends TestCase
{
    use DatabaseMigrations, ReviewMaturityFixtures;

    protected function tearDown(): void
    {
        try {
            if ($this->app) {
                foreach ($this->workflowFixtures as $f) {
                    foreach (['maturity_reassessment_operation_changes', 'maturity_reassessment_operation_assignments'] as $table) {
                        DB::table($table)->where('organization_id', $f['organization']->id)->where('fixture_id', $f['proof']['fixture_id'])->delete();
                    }
                }
                $this->cleanupReviewMaturity();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_actual_policy_requires_a_separate_current_stage_scoped_reassessment_grant(): void
    {
        $f = $this->reviewMaturityFixture();
        $f['workflow_proof']['reassessment_operation_scope'] = 'CLOSURE_REASSESSMENT_LOCAL_001';
        $stages = DB::table('maturity_workflow_stages')->where('approach_id', $f['approach']->id)->orderBy('stage_ordinal')->get();
        $can = fn (int $id) => $this->asWorkflow($f, 'author', fn () => app(ReviewMaturityAccess::class)->can('open_reassessment', $f['approach']->public_id, $id));
        $this->assertFalse($can($stages[0]->id), 'Initial answer_stage is not reassessment permission.');
        $grant = $this->asWorkflow($f, 'issuer', fn () => app(ManageStageReassessmentOperations::class)
            ->issue($f['author']->public_id, 'open_reassessment', $stages[0]->public_id));
        $this->assertTrue($can($stages[0]->id), 'Actual Policy must evaluate the explicit new grant.');
        $this->assertFalse($can($stages[1]->id), 'A stage-one grant cannot authorize stage two.');
        $this->asWorkflow($f, 'issuer', fn () => app(ManageStageReassessmentOperations::class)->revoke($grant));
        $this->assertFalse($can($stages[0]->id), 'Revocation applies inside an existing session.');
        $this->assertDatabaseHas('maturity_reassessment_operation_changes', ['event' => 'granted']);
        $this->assertDatabaseHas('maturity_reassessment_operation_changes', ['event' => 'revoked']);
        $this->assertSame([], (require config_path('authorization.php'))['capabilities']);
    }
}
