<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use RuntimeException;

/** Test-only, reviewed baseline. See docs/architecture/tenant-isolation-schema-contract.md. */
final class TenantIsolationSchemaContract
{
    // The single executable classification source. Never infer scope from a name.
    public const TABLES = [
        'authorization_changes' => 'direct',
        'cache' => 'global',
        'cache_locks' => 'global',
        'failed_jobs' => 'global',
        'job_batches' => 'global',
        'jobs' => 'global',
        'migrations' => 'global',
        'model_has_permissions' => 'direct',
        'model_has_roles' => 'direct',
        'organization_changes' => 'direct',
        'organization_memberships' => 'direct',
        'organizational_unit_changes' => 'direct',
        'organizational_units' => 'direct',
        'organizations' => 'root',
        'password_reset_tokens' => 'global',
        'permissions' => 'catalog',
        'role_has_permissions' => 'indirect',
        'roles' => 'direct',
        'sessions' => 'global',
        'users' => 'global',
    ];

    // Ordered local columns => [target table, ordered target columns]. All are
    // same-schema RESTRICT/RESTRICT. Global actors are NOT membership references.
    private const FOREIGN_KEYS = [
        'organization_memberships' => [
            'user_id' => ['users', 'id'], 'organization_id' => ['organizations', 'id'],
        ],
        'organizational_units' => [
            'organization_id' => ['organizations', 'id'],
            'organization_id,parent_id' => ['organizational_units', 'organization_id,id'],
        ],
        'roles' => ['organization_id' => ['organizations', 'id']],
        'role_has_permissions' => [
            'role_id' => ['roles', 'id'], 'permission_id' => ['permissions', 'id'],
        ],
        'model_has_roles' => [
            'organization_id,role_id' => ['roles', 'organization_id,id'],
            'model_id,organization_id' => ['organization_memberships', 'user_id,organization_id'],
        ],
        'model_has_permissions' => [
            'permission_id' => ['permissions', 'id'],
            'model_id,organization_id' => ['organization_memberships', 'user_id,organization_id'],
        ],
        'authorization_changes' => [
            'organization_id' => ['organizations', 'id'], 'actor_id' => ['users', 'id'],
            'subject_user_id' => ['users', 'id'],
        ],
        'organizational_unit_changes' => [
            'organization_id' => ['organizations', 'id'], 'actor_id' => ['users', 'id'],
            'organization_id,unit_id' => ['organizational_units', 'organization_id,id'],
            'organization_id,previous_parent_id' => ['organizational_units', 'organization_id,id'],
            'organization_id,new_parent_id' => ['organizational_units', 'organization_id,id'],
        ],
        'organization_changes' => [
            'organization_id' => ['organizations', 'id'], 'actor_id' => ['users', 'id'],
        ],
    ];

    private const UNIQUE_KEYS = [
        'users' => ['id'],
        'organizations' => ['id', 'public_id'],
        'organization_memberships' => ['id', 'user_id,organization_id'],
        'organizational_units' => ['id', 'public_id', 'organization_id,id'],
        'permissions' => ['id', 'name,guard_name'],
        'roles' => ['id', 'organization_id,id', 'organization_id,name,guard_name'],
        'role_has_permissions' => ['permission_id,role_id'],
        'model_has_roles' => ['organization_id,role_id,model_id,model_type'],
        'model_has_permissions' => ['organization_id,permission_id,model_id,model_type'],
        'authorization_changes' => ['id'],
        'organizational_unit_changes' => ['id'],
        'organization_changes' => ['id'],
    ];

    private const NULLABLE_REFERENCES = [
        'organizational_units' => ['parent_id'],
        'authorization_changes' => ['subject_user_id'],
        'organizational_unit_changes' => ['previous_parent_id', 'new_parent_id'],
    ];

    private const CHECKS = [
        'organizational_unit_changes' => ['unit_changes_event_check', "(eventin('created','renamed','reparented'))"],
        'organization_changes' => ['organization_changes_event_check', "(eventin('created','renamed'))"],
    ];

    /** Read only metadata, never tenant rows. Call after MySqlTestCase's guards. */
    public static function read(Connection $db): array
    {
        if ($db->getDriverName() !== 'mysql') {
            throw new RuntimeException('The schema contract requires actual MySQL metadata.');
        }
        $schema = [];
        foreach ($db->select("SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME") as $table) {
            $schema[$table->name] = ['engine' => $table->engine, 'columns' => [], 'foreign_keys' => [], 'indexes' => [], 'checks' => []];
        }
        foreach ($db->select('SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col, COLUMN_TYPE AS type, IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION') as $column) {
            if (isset($schema[$column->tbl])) {
                $schema[$column->tbl]['columns'][$column->col] = ['type' => $column->type, 'nullable' => $column->nullable === 'YES'];
            }
        }
        $foreignKeys = $db->select(<<<'SQL'
SELECT k.TABLE_NAME AS tbl, k.CONSTRAINT_NAME AS name,
GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION) AS column_list,
k.REFERENCED_TABLE_SCHEMA AS target_schema, k.REFERENCED_TABLE_NAME AS target,
GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION) AS reference_list,
r.DELETE_RULE AS delete_rule, r.UPDATE_RULE AS update_rule
FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
ON k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA AND k.TABLE_NAME = r.TABLE_NAME AND k.CONSTRAINT_NAME = r.CONSTRAINT_NAME
WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL
GROUP BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, r.DELETE_RULE, r.UPDATE_RULE
ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME
SQL);
        foreach ($foreignKeys as $key) {
            $schema[$key->tbl]['foreign_keys'][$key->name] = [
                'columns' => $key->column_list, 'target' => $key->target, 'references' => $key->reference_list,
                'local' => $key->target_schema === $db->getDatabaseName(), 'delete' => $key->delete_rule, 'update' => $key->update_rule,
            ];
        }
        $indexes = $db->select(<<<'SQL'
SELECT TABLE_NAME AS tbl, INDEX_NAME AS name, NON_UNIQUE AS non_unique,
GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS column_list,
SUM(SUB_PART IS NOT NULL OR COLUMN_NAME IS NULL) AS partial_columns
FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE ORDER BY TABLE_NAME, INDEX_NAME
SQL);
        foreach ($indexes as $index) {
            $schema[$index->tbl]['indexes'][$index->name] = [
                'columns' => $index->column_list, 'unique' => (int) $index->non_unique === 0, 'full' => (int) $index->partial_columns === 0,
            ];
        }
        $checks = $db->select(<<<'SQL'
SELECT t.TABLE_NAME AS tbl, t.CONSTRAINT_NAME AS name, t.ENFORCED AS enforced, c.CHECK_CLAUSE AS clause
FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c
ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME
WHERE t.TABLE_SCHEMA = DATABASE() AND t.CONSTRAINT_TYPE = 'CHECK'
ORDER BY t.TABLE_NAME, t.CONSTRAINT_NAME
SQL);
        foreach ($checks as $check) {
            // Only the two fixed literal event expressions above, not a SQL parser.
            // Preserve literal contents: whitespace/charset text inside a value
            // must never make a different event appear to be an approved one.
            $clause = preg_replace_callback("/'[^']*'|`event`|_utf8mb4|\\s+/", static fn (array $match): string => match (true) {
                str_starts_with($match[0], "'") => $match[0],
                $match[0] === '`event`' => 'event',
                default => '',
            }, str_replace("\\'", "'", $check->clause));
            $schema[$check->tbl]['checks'][$check->name] = ['enforced' => $check->enforced === 'YES', 'clause' => $clause];
        }

        return $schema;
    }

    /** @return list<string> Schema names only; no row values or SQL credentials. */
    public static function violations(array $schema): array
    {
        $errors = [];
        foreach (array_diff(array_keys($schema), array_keys(self::TABLES)) as $table) {
            $errors[] = "$table: unclassified base table; add a reviewed tenant-scope classification.";
        }
        foreach (self::TABLES as $table => $scope) {
            if (! isset($schema[$table])) {
                $errors[] = "$table: reviewed base table is missing or renamed.";

                continue;
            }
            $actual = $schema[$table];
            if ($actual['engine'] !== 'InnoDB') {
                $errors[] = "$table: expected InnoDB for the reviewed integrity contract.";
            }
            if ($scope === 'direct' && ($actual['columns']['organization_id'] ?? null) !== ['type' => 'bigint unsigned', 'nullable' => false]) {
                $errors[] = "$table: organization_id must be non-null BIGINT UNSIGNED.";
            }
            if (in_array($scope, ['direct', 'indirect'], true) && ! isset(self::FOREIGN_KEYS[$table])) {
                $errors[] = "$table: tenant classification requires explicit reviewed foreign-key relationships.";
            }
            foreach (self::FOREIGN_KEYS[$table] ?? [] as $columns => [$target, $references]) {
                foreach (explode(',', $columns) as $column) {
                    if ($column === 'organization_id') {
                        continue; // Already checked for every directly scoped table.
                    }
                    $nullable = in_array($column, self::NULLABLE_REFERENCES[$table] ?? [], true);
                    if (($actual['columns'][$column] ?? null) !== ['type' => 'bigint unsigned', 'nullable' => $nullable]) {
                        $errors[] = "$table: $column must retain its reviewed BIGINT UNSIGNED type and nullability.";
                    }
                }
                $required = ['columns' => $columns, 'target' => $target, 'references' => $references,
                    'local' => true, 'delete' => 'RESTRICT', 'update' => 'RESTRICT'];
                if (! in_array($required, $actual['foreign_keys'], true)) {
                    $errors[] = "$table: required restrictive FK ($columns) -> $target($references) in the same schema is missing or changed.";
                }
            }
            foreach (self::UNIQUE_KEYS[$table] ?? [] as $columns) {
                if (! in_array(['columns' => $columns, 'unique' => true, 'full' => true], $actual['indexes'], true)) {
                    $errors[] = "$table: required full-column UNIQUE ($columns) is missing or changed.";
                }
            }
            if (isset(self::CHECKS[$table])) {
                [$name, $clause] = self::CHECKS[$table];
                if (($actual['columns']['event'] ?? null) !== ['type' => 'varchar(20)', 'nullable' => false]) {
                    $errors[] = "$table: event must remain non-null VARCHAR(20).";
                }
                if (($actual['checks'][$name] ?? null) !== ['enforced' => true, 'clause' => $clause]) {
                    $errors[] = "$table: required enforced CHECK $name is missing or changed.";
                }
            }
            // An added cascade must not erase history through a new reference.
            if (in_array($table, ['authorization_changes', 'organizational_unit_changes', 'organization_changes'], true)) {
                foreach ($actual['foreign_keys'] as $key) {
                    if ($key['delete'] !== 'RESTRICT' || $key['update'] !== 'RESTRICT') {
                        $errors[] = "$table: every historical foreign key must remain RESTRICT on delete and update.";
                    }
                }
            }
        }
        $errors = array_values(array_unique($errors));
        sort($errors);

        return $errors;
    }
}
