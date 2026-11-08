<?php

namespace Tests\Feature\Filament;

use App\Actions\Approaches\CreateApproach;
use App\Authorization\TenantContext;
use App\Filament\Institution\Approaches\ApproachAccess;
use App\Filament\Institution\Resources\ApproachResource\Pages\ApproachBank;
use App\Models\Approach;
use App\Models\OrganizationalUnit;
use App\Policies\ApproachPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

require_once __DIR__.'/ApproachFilamentIntegrationTest.php';

class InstitutionPanelPerformanceTest extends TestCase
{
    use ApproachPanelFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SyntheticApproachPolicy::$grants = [];
        app()->bind(ApproachPolicy::class, SyntheticApproachPolicy::class);
        Filament::setCurrentPanel(Filament::getPanel('institution'));
        Filament::bootCurrentPanel();
    }

    public function test_owner_server_search_reaches_after_100_without_preloading_or_cross_tenant_leak(): void
    {
        [$actor, $organization] = $this->fixture44();
        for ($i = 0; $i < 125; $i++) {
            OrganizationalUnit::factory()->for($organization)->create(['name' => sprintf('وحدة %03d', $i)]);
        }
        $last = OrganizationalUnit::factory()->for($organization)->create(['name' => 'وحدة 999 بعيدة']);
        [, $foreign] = $this->fixture44();
        $other = OrganizationalUnit::factory()->for($foreign)->create(['name' => 'وحدة 999 أجنبية']);
        $this->login44($actor, $organization);
        $access = app(ApproachAccess::class);
        $this->assertLessThanOrEqual(20, count($access->searchOwners('create')));
        $this->assertArrayNotHasKey($last->public_id, $access->searchOwners('create'));
        $this->assertSame([$last->public_id => $last->name], $access->searchOwners('create', search: '999'));
        $this->assertSame([], $access->searchOwners('create', selected: $other->public_id));
        $this->assertSame([], $access->searchOwners('create', search: '%'));
        Livewire::test(ApproachBank::class)->callAction('create', data: $this->data44($last, ['name' => 'مالك بعد المئة']))->assertHasNoActionErrors();
        $this->assertDatabaseHas('approaches', ['name' => 'مالك بعد المئة', 'owning_unit_id' => $last->id, 'organization_id' => $organization->id]);
    }

    public function test_moderate_list_has_constant_query_count_for_one_vs_twenty_and_reports_timings(): void
    {
        [$actor, $organization, $unit] = $this->fixture44();
        app(TenantContext::class)->run($actor, $organization->id, function () use ($actor, $unit): void {
            for ($i = 0; $i < 119; $i++) {
                app(CreateApproach::class)->execute($actor, $this->data44($unit, ['name' => 'منهجية قياس '.$i]));
            }
        });
        $this->login44($actor, $organization);
        $counts = [];
        $timings = [];
        try {
            DB::enableQueryLog();
            foreach ([1, 20] as $perPage) {
                DB::flushQueryLog();
                $start = hrtime(true);
                $list = app(ApproachAccess::class)->listing(perPage: $perPage);
                $timings[] = (hrtime(true) - $start) / 1000000;
                $counts[] = count(DB::getQueryLog());
                $this->assertCount($perPage, $list->items());
                $this->assertSame(120, $list->total());
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame($counts[0], $counts[1], 'No per-row permission transaction/query for view-link hints.');
        $this->assertSame(120, Approach::count());
        if (getenv('ISSUE46_PERFORMANCE_RECEIPT') === '1') {
            $path = base_path('.local-tools/reports/phase-10/46');
            if (! is_dir($path)) {
                mkdir($path, 0777, true);
            }
            file_put_contents($path.'/list-performance.json', json_encode(['database' => DB::getDriverName(), 'fixture_approaches' => 120,
                'page_sizes' => [1, 20], 'query_counts' => $counts, 'durations_ms' => $timings, 'sla' => 'not established'], JSON_PRETTY_PRINT));
        }
    }
}
