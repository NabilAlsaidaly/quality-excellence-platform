<?php

namespace Tests\Integration\MySql;

use App\Models\Approach;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Feature\Filament\InstitutionJourneyScenarios;
use Tests\Support\MySqlTestCase;

require_once dirname(__DIR__, 2).'/Feature/Filament/InstitutionPanelJourneyTest.php';

class InstitutionPanelJourneyTest extends MySqlTestCase
{
    use DatabaseMigrations, InstitutionJourneyScenarios;

    private array $ownedApproaches46 = [];

    private array $ownedUsers46 = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::listen('eloquent.created: '.Approach::class, function (Approach $row): void {
            $this->ownedApproaches46[] = $row->id;
        });
        Event::listen('eloquent.created: '.User::class, function (User $row): void {
            $this->ownedUsers46[] = $row->id;
        });
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if (DB::connection()->getDatabaseName() !== 'quality_platform_ci' || getenv('MYSQL_TESTS_ENABLED') !== '1') {
            throw new RuntimeException('Refuse Issue46 cleanup outside disposable MySQL.');
        }
        foreach (['approach_changes', 'approach_content_revisions'] as $table) {
            DB::table($table)->whereIn('approach_id', $this->ownedApproaches46)->delete();
        }
        DB::table('approaches')->whereIn('id', $this->ownedApproaches46)->delete();
        DB::table('authorization_changes')->whereIn('actor_id', $this->ownedUsers46)->delete();
        foreach (['model_has_roles', 'model_has_permissions'] as $table) {
            DB::table($table)->where('model_type', User::class)->whereIn('model_id', $this->ownedUsers46)->delete();
        }
        DB::table('organization_memberships')->whereIn('user_id', $this->ownedUsers46)->delete();
        DB::table('users')->whereIn('id', $this->ownedUsers46)->delete();
        parent::tearDown();
    }
}
