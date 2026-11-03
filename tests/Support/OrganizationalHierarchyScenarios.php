<?php

namespace Tests\Support;

use App\Actions\OrganizationalUnits\InvalidHierarchy;
use App\Actions\OrganizationalUnits\OrganizationalUnitManagement;
use App\Authorization\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationMembership;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

trait OrganizationalHierarchyScenarios
{
    private User $actor;

    private Organization $organization;

    private Organization $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create();
        $this->organization = Organization::factory()->create();
        $this->foreign = Organization::factory()->create();
        OrganizationMembership::factory()->for($this->actor)->for($this->organization)->create();
    }

    public function test_internal_create_supports_multiple_roots_children_and_arabic_duplicate_names(): void
    {
        $this->inOrganization(function (): void {
            $service = $this->service();
            $root = $service->create($this->actor, 'قسم الجودة');
            $other = $service->create($this->actor, 'قسم الجودة');
            $first = $service->create($this->actor, 'التطوير 🌱', $root->public_id);
            $second = $service->create($this->actor, 'التطوير 🌱', $root->public_id);
            $this->assertNull($root->parent_id);
            $this->assertNull($other->parent_id);
            $this->assertSame($this->organization->id, $root->organization_id);
            $this->assertSame($root->id, $first->parent_id);
            $this->assertSame($root->id, $second->parent_id);
            $this->assertSame('التطوير 🌱', $first->fresh()->name);
            $this->assertCount(2, $root->fresh()->children);
            $this->assertTrue($root->is($first->fresh()->parent));
            $ids = [$root->public_id, $other->public_id, $first->public_id, $second->public_id];
            $this->assertCount(4, array_unique($ids));
            foreach ($ids as $id) {
                $this->assertTrue(Str::isUlid($id));
            }
            $this->assertDatabaseCount('organizational_unit_changes', 4);
            $this->assertDatabaseCount('authorization_changes', 0);
            $this->assertDatabaseCount('permissions', 0);
            $this->assertDatabaseCount('roles', 0);
        });
    }

    public static function invalidNames(): array
    {
        return ['empty' => [''], 'spaces' => [" \t\n "], 'unicode spaces' => ["\u{00a0}\u{2003}"],
            'overlong ascii' => [str_repeat('a', 256)], 'overlong unicode' => [str_repeat('ج', 256)]];
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_create_and_rename_names_do_not_write_or_audit(string $name): void
    {
        $unit = OrganizationalUnit::factory()->for($this->organization)->create();
        $before = $unit->fresh()->getAttributes();
        $this->inOrganization(function () use ($name, $unit): void {
            $this->expectFailure(fn () => $this->service()->create($this->actor, $name), ValidationException::class);
            $this->expectFailure(fn () => $this->service()->rename($this->actor, $unit->public_id, $name), ValidationException::class);
        });
        $this->assertSame($before, $unit->fresh()->getAttributes());
        $this->assertDatabaseCount('organizational_units', 1);
        $this->assertDatabaseCount('organizational_unit_changes', 0);
    }

    public function test_name_column_boundary_and_rename_preserve_scope_parent_ulid_and_descendants(): void
    {
        $this->inOrganization(function (): void {
            $parent = $this->service()->create($this->actor, 'Parent');
            $unit = $this->service()->create($this->actor, str_repeat('ج', 255), $parent->public_id);
            $child = $this->service()->create($this->actor, 'Child', $unit->public_id);
            $before = $unit->only(['id', 'public_id', 'organization_id', 'parent_id', 'created_at']);
            $childBefore = $child->fresh()->getAttributes();
            $renamed = $this->service()->rename($this->actor, $unit->public_id, '  إدارة التطوير  ');
            $this->assertSame('إدارة التطوير', $renamed->name);
            $this->assertEquals($before, $renamed->only(array_keys($before)));
            $this->assertSame($childBefore, $child->fresh()->getAttributes());
            $event = DB::table('organizational_unit_changes')->where('event', 'renamed')->sole();
            $this->assertSame(str_repeat('ج', 255), $event->previous_name);
            $this->assertSame('إدارة التطوير', $event->new_name);
            $this->assertSame($parent->id, $event->previous_parent_id);
            $this->assertSame($parent->id, $event->new_parent_id);
        });
    }

    public function test_moves_between_parents_to_root_and_root_beneath_parent_preserve_subtree(): void
    {
        $this->inOrganization(function (): void {
            $first = $this->service()->create($this->actor, 'First');
            $second = $this->service()->create($this->actor, 'Second');
            $child = $this->service()->create($this->actor, 'Child', $first->public_id);
            $grandchild = $this->service()->create($this->actor, 'Grandchild', $child->public_id);
            $grandchildBefore = $grandchild->fresh()->getAttributes();
            $childUlid = $child->public_id;
            $moved = $this->service()->move($this->actor, $child->public_id, $second->public_id);
            $this->assertSame($second->id, $moved->parent_id);
            $this->assertSame($childUlid, $moved->public_id);
            $this->assertSame($grandchildBefore, $grandchild->fresh()->getAttributes());
            $this->assertNull($this->service()->move($this->actor, $child->public_id, null)->parent_id);
            $this->assertSame($first->id, $this->service()->move($this->actor, $child->public_id, $first->public_id)->parent_id);
            $this->assertSame($second->id, $this->service()->move($this->actor, $first->public_id, $second->public_id)->parent_id);
            $this->assertSame($first->id, $child->fresh()->parent_id);
            $this->assertSame($child->id, $grandchild->fresh()->parent_id);
            $this->assertDatabaseCount('organizational_unit_changes', 8);
        });
    }

    public static function forbiddenCycleDepths(): array
    {
        return ['self' => [0], 'two nodes' => [1], 'three nodes' => [2], 'deep chain' => [40]];
    }

    #[DataProvider('forbiddenCycleDepths')]
    public function test_self_and_multi_level_descendant_cycles_are_rejected(int $depth): void
    {
        $root = OrganizationalUnit::factory()->for($this->organization)->create();
        $candidate = $root;
        for ($i = 0; $i < $depth; $i++) {
            $candidate = OrganizationalUnit::factory()->childOf($candidate)->create();
        }
        $before = DB::table('organizational_units')->orderBy('id')->get()->toJson();
        $this->inOrganization(fn () => $this->expectFailure(
            fn () => $this->service()->move($this->actor, $root->public_id, $candidate->public_id),
            InvalidHierarchy::class));
        $this->assertSame($before, DB::table('organizational_units')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('organizational_unit_changes', 0);
    }

    public function test_same_parent_and_same_name_are_true_no_ops(): void
    {
        $parent = OrganizationalUnit::factory()->for($this->organization)->create();
        $child = OrganizationalUnit::factory()->childOf($parent)->create();
        $before = $child->fresh()->getAttributes();
        $this->inOrganization(function () use ($parent, $child): void {
            $this->service()->move($this->actor, $child->public_id, $parent->public_id);
            $this->service()->rename($this->actor, $child->public_id, $child->name);
            $this->service()->move($this->actor, $parent->public_id, null);
        });
        $this->assertSame($before, $child->fresh()->getAttributes());
        $this->assertDatabaseCount('organizational_unit_changes', 0);
    }

    public function test_missing_malformed_and_foreign_identifiers_are_indistinguishable_and_never_write(): void
    {
        $foreignUnit = OrganizationalUnit::factory()->for($this->foreign)->create();
        $unit = OrganizationalUnit::factory()->for($this->organization)->create();
        $messages = [];
        $this->inOrganization(function () use ($foreignUnit, $unit, &$messages): void {
            foreach ([$foreignUnit->public_id, (string) Str::ulid(), 'forged-id', (string) $unit->id] as $id) {
                foreach ([
                    fn () => $this->service()->create($this->actor, 'Uncreated', $id),
                    fn () => $this->service()->rename($this->actor, $id, 'Unrenamed'),
                    fn () => $this->service()->move($this->actor, $id, null),
                    fn () => $this->service()->move($this->actor, $unit->public_id, $id),
                ] as $operation) {
                    try {
                        $operation();
                        $this->fail('Missing/foreign resource must fail.');
                    } catch (ModelNotFoundException $exception) {
                        $messages[] = $exception->getMessage();
                        $this->addToAssertionCount(1);
                    }
                }
            }
        });
        $this->assertCount(1, array_unique($messages));
        $this->assertSame($foreignUnit->name, $foreignUnit->fresh()->name);
        $this->assertNull($unit->fresh()->parent_id);
        $this->assertDatabaseCount('organizational_units', 2);
        $this->assertDatabaseCount('organizational_unit_changes', 0);
    }

    public function test_absent_guest_wrong_actor_and_revoked_context_deny_all_operations(): void
    {
        $unit = OrganizationalUnit::factory()->for($this->organization)->create();
        $this->deniedOperations($this->actor, $unit);
        $this->inOrganization(function () use ($unit): void {
            $this->deniedOperations(null, $unit);
            $this->deniedOperations(User::factory()->create(), $unit);
            OrganizationMembership::query()->where('user_id', $this->actor->id)
                ->where('organization_id', $this->organization->id)->delete();
            $this->deniedOperations($this->actor, $unit);
        });
        $this->expectFailure(fn () => app(TenantContext::class)->run($this->actor, $this->foreign->public_id,
            fn () => $this->service()->create($this->actor, 'Forbidden')), AuthorizationException::class);
        $this->assertDatabaseCount('organizational_unit_changes', 0);
    }

    public function test_tree_is_empty_or_deterministic_tenant_only_indexed_adjacency_data(): void
    {
        $this->inOrganization(fn () => $this->assertSame(['roots' => [], 'nodes' => []], $this->service()->tree($this->actor)));
        $root = OrganizationalUnit::factory()->for($this->organization)->create(['name' => 'Z']);
        $other = OrganizationalUnit::factory()->for($this->organization)->create(['name' => 'A']);
        $child = OrganizationalUnit::factory()->childOf($root)->create();
        $sibling = OrganizationalUnit::factory()->childOf($root)->create();
        $leaf = OrganizationalUnit::factory()->childOf($child)->create();
        $foreignUnit = OrganizationalUnit::factory()->for($this->foreign)->create();
        $this->inOrganization(function () use ($root, $other, $child, $sibling, $leaf, $foreignUnit): void {
            $tree = $this->service()->tree($this->actor);
            $this->assertSame([$root->public_id, $other->public_id], $tree['roots']);
            $this->assertSame([$child->public_id, $sibling->public_id], $tree['nodes'][$root->public_id]['children']);
            $this->assertSame([$leaf->public_id], $tree['nodes'][$child->public_id]['children']);
            $this->assertSame($root->public_id, $tree['nodes'][$child->public_id]['parent_public_id']);
            $this->assertSame($root->id, $tree['nodes'][$root->public_id]['id']);
            $this->assertArrayNotHasKey($foreignUnit->public_id, $tree['nodes']);
            $this->assertSame($tree, $this->service()->tree($this->actor));
            $this->assertDatabaseCount('organizational_unit_changes', 0);
        });
    }

    public function test_tree_construction_uses_one_units_query_for_many_nodes_without_recursion(): void
    {
        $node = OrganizationalUnit::factory()->for($this->organization)->create();
        for ($i = 0; $i < 120; $i++) {
            $node = OrganizationalUnit::factory()->childOf($node)->create();
        }
        $this->inOrganization(function (): void {
            DB::connection()->enableQueryLog();
            DB::connection()->flushQueryLog();
            try {
                $tree = $this->service()->tree($this->actor);
                $queries = DB::connection()->getQueryLog();
            } finally {
                DB::connection()->disableQueryLog();
                DB::connection()->flushQueryLog();
            }
            $unitsQueries = array_filter($queries, fn ($query) => str_contains($query['query'], 'organizational_units'));
            $this->assertCount(121, $tree['nodes']);
            $this->assertCount(1, $tree['roots']);
            $this->assertCount(1, $unitsQueries);
            $this->assertLessThanOrEqual(8, count($queries));
        });
    }

    public function test_raw_sql_multi_level_cycle_is_detected_by_tree_and_mutations_without_repair(): void
    {
        $a = OrganizationalUnit::factory()->for($this->organization)->create();
        $b = OrganizationalUnit::factory()->childOf($a)->create();
        $unrelated = OrganizationalUnit::factory()->for($this->organization)->create();
        // Intentional isolated corruption: real raw SQL bypasses application
        // multi-level checks; existing FK/direct-self-parent guarantees stay on.
        DB::table('organizational_units')->where('id', $a->id)->update(['parent_id' => $b->id]);
        $this->inOrganization(function () use ($a, $b, $unrelated): void {
            foreach ([
                fn () => $this->service()->tree($this->actor),
                fn () => $this->service()->create($this->actor, 'Uncreated', $a->public_id),
                fn () => $this->service()->rename($this->actor, $a->public_id, 'Unrenamed'),
                fn () => $this->service()->move($this->actor, $a->public_id, null),
                fn () => $this->service()->move($this->actor, $unrelated->public_id, $b->public_id),
            ] as $operation) {
                $this->expectFailure($operation, InvalidHierarchy::class);
            }
        });
        $this->assertSame($b->id, $a->fresh()->parent_id);
        $this->assertSame($a->id, $b->fresh()->parent_id);
        $this->assertDatabaseCount('organizational_unit_changes', 0);
    }

    public function test_mutation_and_audit_rollback_together_and_no_rbac_audit_is_misused(): void
    {
        $this->inOrganization(function (): void {
            try {
                DB::transaction(function (): void {
                    $root = $this->service()->create($this->actor, 'Temporary');
                    $child = $this->service()->create($this->actor, 'Child', $root->public_id);
                    $this->service()->rename($this->actor, $child->public_id, 'Changed');
                    $this->service()->move($this->actor, $child->public_id, null);
                    $this->assertDatabaseCount('organizational_unit_changes', 4);
                    throw new RuntimeException('Fixture rollback');
                });
            } catch (RuntimeException $exception) {
                $this->assertSame('Fixture rollback', $exception->getMessage());
            }
            $this->assertDatabaseCount('organizational_units', 0);
            $this->assertDatabaseCount('organizational_unit_changes', 0);
            $this->assertDatabaseCount('authorization_changes', 0);
        });
    }

    public function test_audit_failure_rolls_back_each_actual_mutation(): void
    {
        $a = OrganizationalUnit::factory()->for($this->organization)->create();
        $b = OrganizationalUnit::factory()->for($this->organization)->create();
        $armed = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed): void {
            if ($armed && str_contains($sql, 'insert into') && str_contains($sql, 'organizational_unit_changes')) {
                throw new RuntimeException('Synthetic audit failure');
            }
        });
        $this->inOrganization(function () use ($a, $b, &$armed): void {
            $armed = true;
            try {
                foreach ([
                    fn () => $this->service()->create($this->actor, 'Uncreated'),
                    fn () => $this->service()->rename($this->actor, $a->public_id, 'Unrenamed'),
                    fn () => $this->service()->move($this->actor, $a->public_id, $b->public_id),
                ] as $operation) {
                    $this->expectFailure($operation, RuntimeException::class);
                    $this->assertSame($a->name, $a->fresh()->name);
                    $this->assertNull($a->fresh()->parent_id);
                    $this->assertDatabaseCount('organizational_units', 2);
                    $this->assertDatabaseCount('organizational_unit_changes', 0);
                }
            } finally {
                $armed = false;
            }
        });
    }

    public function test_success_audit_attribution_and_minimal_changed_fields(): void
    {
        $this->inOrganization(function (): void {
            $root = $this->service()->create($this->actor, 'Root');
            $unit = $this->service()->create($this->actor, 'Old', $root->public_id);
            $this->service()->rename($this->actor, $unit->public_id, 'New');
            $this->service()->move($this->actor, $unit->public_id, null);
            $events = DB::table('organizational_unit_changes')->orderBy('id')->get();
            $this->assertSame(['created', 'created', 'renamed', 'reparented'], $events->pluck('event')->all());
            foreach ($events as $event) {
                $this->assertSame($this->organization->id, $event->organization_id);
                $this->assertSame($this->actor->id, $event->actor_id);
                $this->assertNotNull($event->created_at);
                $this->assertStringNotContainsString($this->actor->email, json_encode($event));
                $this->assertStringNotContainsString($this->actor->password, json_encode($event));
            }
            $move = $events->last();
            $this->assertSame($unit->id, $move->unit_id);
            $this->assertSame($root->id, $move->previous_parent_id);
            $this->assertNull($move->new_parent_id);
            $this->assertNull($move->previous_name);
            $this->assertNull($move->new_name);
        });
    }

    public function test_hierarchy_exposes_no_management_route_or_implicit_permission(): void
    {
        $this->assertSame([], config('authorization.capabilities'));
        $this->assertDatabaseCount('permissions', 0);
        $this->assertDatabaseCount('roles', 0);
        $this->assertFalse(Route::has('organizational-units.store'));
        $this->assertFalse(Route::has('organizational-units.move'));
        $this->get('/admin/organizational-units')->assertNotFound();
        $this->post('/organizational-units')->assertNotFound();
    }

    private function deniedOperations(?User $actor, OrganizationalUnit $unit): void
    {
        foreach ([
            fn () => $this->service()->create($actor, 'Forbidden'),
            fn () => $this->service()->rename($actor, $unit->public_id, 'Forbidden'),
            fn () => $this->service()->move($actor, $unit->public_id, null),
            fn () => $this->service()->tree($actor),
        ] as $operation) {
            $this->expectFailure($operation, AuthorizationException::class);
        }
    }

    private function inOrganization(Closure $operation): mixed
    {
        return app(TenantContext::class)->run($this->actor, $this->organization->public_id, $operation);
    }

    private function service(): OrganizationalUnitManagement
    {
        return app(OrganizationalUnitManagement::class);
    }

    private function expectFailure(Closure $operation, string $class): void
    {
        try {
            $operation();
            $this->fail('Expected failure: '.$class);
        } catch (\Throwable $error) {
            $this->assertInstanceOf($class, $error);
        }
    }
}
