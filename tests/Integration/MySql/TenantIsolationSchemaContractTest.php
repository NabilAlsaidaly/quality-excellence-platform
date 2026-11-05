<?php

namespace Tests\Integration\MySql;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\Support\MySqlTestCase;
use Tests\Support\TenantIsolationSchemaContract as Contract;

class TenantIsolationSchemaContractTest extends MySqlTestCase
{
    use DatabaseMigrations;

    public function test_every_actual_base_table_has_its_reviewed_scope_and_required_constraints(): void
    {
        $schema = Contract::read(DB::connection());
        $this->assertContractPasses($schema);

        // These genuine exceptions must pass without inventing organization_id.
        foreach (['organizations' => 'root', 'permissions' => 'catalog',
            'role_has_permissions' => 'indirect'] as $table => $scope) {
            $this->assertSame($scope, Contract::TABLES[$table]);
            $this->assertArrayNotHasKey('organization_id', $schema[$table]['columns']);
        }
        // Mixed principal storage now has an explicitly nullable institution
        // binding. Historical/global principals remain valid; it is not blanket
        // direct tenant ownership or a reason to weaken any other exception.
        $this->assertSame('global', Contract::TABLES['users']);
        $this->assertSame(['type' => 'bigint unsigned', 'nullable' => true], $schema['users']['columns']['organization_id']);
    }

    public function test_an_unknown_actual_table_fails_even_with_an_organization_column(): void
    {
        $before = Contract::read(DB::connection());
        $this->assertContractPasses($before);
        // CREATE must succeed before cleanup owns this exact disposable fixture.
        DB::statement('CREATE TABLE phase04c_unreviewed_tenant_records (id BIGINT UNSIGNED PRIMARY KEY, organization_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB');
        try {
            $schema = Contract::read(DB::connection());
            $this->assertSame([
                'phase04c_unreviewed_tenant_records: unclassified base table; add a reviewed tenant-scope classification.',
            ], Contract::violations($schema));

            // A plausible infrastructure name is not automatic global approval.
            $schema['unreviewed_infrastructure'] = $schema['phase04c_unreviewed_tenant_records'];
            $this->assertContains('unreviewed_infrastructure: unclassified base table; add a reviewed tenant-scope classification.', Contract::violations($schema));
        } finally {
            DB::statement('DROP TABLE phase04c_unreviewed_tenant_records');
        }
        $this->assertSame($before, Contract::read(DB::connection()));
        $this->assertContractPasses(Contract::read(DB::connection()));
    }

    public function test_removing_the_real_indirect_scope_fk_is_detected_and_restored(): void
    {
        $before = Contract::read(DB::connection());
        $this->assertContractPasses($before);
        DB::statement('ALTER TABLE role_has_permissions DROP FOREIGN KEY role_permissions_role_fk');
        try {
            $this->assertSame([
                'role_has_permissions: required restrictive FK (role_id) -> roles(id) in the same schema is missing or changed.',
            ], Contract::violations(Contract::read(DB::connection())));
        } finally {
            DB::statement('ALTER TABLE role_has_permissions ADD CONSTRAINT role_permissions_role_fk FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT ON UPDATE RESTRICT');
        }
        $this->assertSame($before, Contract::read(DB::connection()));
        $this->assertContractPasses(Contract::read(DB::connection()));
    }

    public function test_missing_or_weakened_metadata_cannot_pass_the_gate(): void
    {
        $schema = Contract::read(DB::connection());
        $this->assertContractPasses($schema);
        // Mutate actual metadata copies, never migrations or persistent DB state.
        $mutations = [
            ['sessions', null, 'sessions: reviewed base table is missing or renamed.'],
            ['roles.columns.organization_id', null, 'roles: organization_id must be non-null BIGINT UNSIGNED.'],
            ['roles.columns.organization_id.nullable', true, 'roles: organization_id must be non-null BIGINT UNSIGNED.'],
            ['roles.columns.organization_id.type', 'bigint', 'roles: organization_id must be non-null BIGINT UNSIGNED.'],
            ['roles.engine', 'MyISAM', 'roles: expected InnoDB for the reviewed integrity contract.'],
            ['organizational_unit_changes.columns.unit_id.nullable', true, 'organizational_unit_changes: unit_id must retain its reviewed BIGINT UNSIGNED type and nullability.'],
            ['role_has_permissions.columns.role_id', null, 'role_has_permissions: role_id must retain its reviewed BIGINT UNSIGNED type and nullability.'],
            ['organization_changes.columns.actor_id.nullable', true, 'organization_changes: actor_id must retain its reviewed BIGINT UNSIGNED type and nullability.'],
            ['model_has_roles.foreign_keys.assigned_roles_scope_fk.target', 'permissions', 'model_has_roles: required restrictive FK (organization_id,role_id) -> roles(organization_id,id) in the same schema is missing or changed.'],
            ['model_has_roles.foreign_keys.assigned_roles_scope_fk.columns', 'role_id,organization_id', 'model_has_roles: required restrictive FK (organization_id,role_id) -> roles(organization_id,id) in the same schema is missing or changed.'],
            ['model_has_permissions.foreign_keys.direct_permissions_membership_fk.local', false, 'model_has_permissions: required restrictive FK (model_id,organization_id) -> organization_memberships(user_id,organization_id) in the same schema is missing or changed.'],
            ['authorization_changes.foreign_keys.authorization_changes_subject_fk.delete', 'CASCADE', 'authorization_changes: required restrictive FK (subject_user_id) -> users(id) in the same schema is missing or changed.'],
            ['organizational_unit_changes.foreign_keys.unit_changes_new_parent_fk.update', 'CASCADE', 'organizational_unit_changes: required restrictive FK (organization_id,new_parent_id) -> organizational_units(organization_id,id) in the same schema is missing or changed.'],
            ['role_has_permissions.indexes.PRIMARY', null, 'role_has_permissions: required full-column UNIQUE (permission_id,role_id) is missing or changed.'],
            ['role_has_permissions.indexes.PRIMARY.unique', false, 'role_has_permissions: required full-column UNIQUE (permission_id,role_id) is missing or changed.'],
            ['permissions.indexes.permissions_name_guard_unique.full', false, 'permissions: required full-column UNIQUE (name,guard_name) is missing or changed.'],
            ['organization_changes.checks.organization_changes_event_check', null, 'organization_changes: required enforced CHECK organization_changes_event_check is missing or changed.'],
            ['organization_changes.checks.organization_changes_event_check.enforced', false, 'organization_changes: required enforced CHECK organization_changes_event_check is missing or changed.'],
            ['organization_changes.columns.event.nullable', true, 'organization_changes: event must remain non-null VARCHAR(20).'],
            ['organizational_unit_changes.checks.unit_changes_event_check.clause', '(1)', 'organizational_unit_changes: required enforced CHECK unit_changes_event_check is missing or changed.'],
            ['authorization_changes.foreign_keys.synthetic_cascade', ['delete' => 'CASCADE', 'update' => 'RESTRICT'], 'authorization_changes: every historical foreign key must remain RESTRICT on delete and update.'],
        ];
        foreach ($mutations as [$path, $replacement, $message]) {
            $changed = $schema;
            if ($replacement === null) {
                Arr::forget($changed, $path);
            } else {
                data_set($changed, $path, $replacement);
            }
            $this->assertContains($message, Contract::violations($changed), $path);
        }
        $renamed = $schema;
        $renamed['renamed_sessions'] = $renamed['sessions'];
        unset($renamed['sessions']);
        $this->assertSame([
            'renamed_sessions: unclassified base table; add a reviewed tenant-scope classification.',
            'sessions: reviewed base table is missing or renamed.',
        ], Contract::violations($renamed));
        $this->assertSame($schema, Contract::read(DB::connection()));
    }

    public function test_actual_check_literal_changes_and_disabled_enforcement_are_detected(): void
    {
        $before = Contract::read(DB::connection());
        $this->assertContractPasses($before);
        DB::statement("ALTER TABLE organization_changes DROP CHECK organization_changes_event_check, ADD CONSTRAINT organization_changes_event_check CHECK (event IN ('cre ated','renamed_utf8mb4'))");
        try {
            $changed = Contract::read(DB::connection());
            $this->assertSame("(eventin('cre ated','renamed_utf8mb4'))", $changed['organization_changes']['checks']['organization_changes_event_check']['clause']);
            $this->assertSame(['organization_changes: required enforced CHECK organization_changes_event_check is missing or changed.'], Contract::violations($changed));

            DB::statement("ALTER TABLE organization_changes DROP CHECK organization_changes_event_check, ADD CONSTRAINT organization_changes_event_check CHECK (event IN ('created','renamed')) NOT ENFORCED");
            $changed = Contract::read(DB::connection());
            $this->assertFalse($changed['organization_changes']['checks']['organization_changes_event_check']['enforced']);
            $this->assertSame(['organization_changes: required enforced CHECK organization_changes_event_check is missing or changed.'], Contract::violations($changed));
        } finally {
            DB::statement("ALTER TABLE organization_changes DROP CHECK organization_changes_event_check, ADD CONSTRAINT organization_changes_event_check CHECK (event IN ('created','renamed')) ENFORCED");
        }
        $this->assertSame($before, Contract::read(DB::connection()));
        $this->assertContractPasses(Contract::read(DB::connection()));
    }

    private function assertContractPasses(array $schema): void
    {
        $violations = Contract::violations($schema);
        $this->assertSame([], $violations, implode("\n", $violations));
    }
}
