<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Assignment candidate supports verified MySQL/SQLite only.');
        }
        $id = $driver === 'mysql' ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $duration = $driver === 'mysql' ? 'expires_at = starts_at + INTERVAL 24 HOUR' : "expires_at = datetime(starts_at, '+24 hours')";
        $fixture = $driver === 'mysql' ? "CHAR_LENGTH(fixture_id) = 32 AND fixture_id NOT REGEXP '[^0-9a-f]'" : "length(fixture_id) = 32 AND fixture_id NOT GLOB '*[^0-9a-f]*'";
        $sql = <<<'SQL'
CREATE TABLE approach_assignments (
id {ID}, public_id CHAR(26) NOT NULL UNIQUE,
organization_id BIGINT UNSIGNED NOT NULL, fixture_id CHAR(32) NOT NULL,
scenario VARCHAR(32) NOT NULL, classification VARCHAR(12) NOT NULL,
grantee_id BIGINT UNSIGNED NOT NULL, issuer_id BIGINT UNSIGNED NOT NULL,
operation VARCHAR(32) NOT NULL, scope_kind VARCHAR(16) NOT NULL,
approach_id BIGINT UNSIGNED NULL, initial_contributor_id BIGINT UNSIGNED NULL,
scope_key BIGINT GENERATED ALWAYS AS (COALESCE(approach_id, 0)) STORED,
starts_at DATETIME NOT NULL, expires_at DATETIME NOT NULL,
revoked_at DATETIME NULL, revoked_by BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL,
CONSTRAINT approach_assignments_tenant_id UNIQUE (organization_id, id),
CONSTRAINT approach_assignments_audit_identity UNIQUE (organization_id, fixture_id, id, grantee_id),
CONSTRAINT approach_assignments_operation_unique UNIQUE (organization_id, fixture_id, grantee_id, operation, scope_key),
CONSTRAINT approach_assignments_test_check CHECK (classification = 'TEST_ONLY' AND scenario = 'J03_LOCAL_APPROACH_001' AND {FIXTURE}),
CONSTRAINT approach_assignments_scope_check CHECK (
 (scope_kind = 'institution' AND approach_id IS NULL AND operation IN ('quality', 'create', 'list')) OR
 (scope_kind = 'approach' AND approach_id IS NOT NULL AND operation IN ('view', 'updateMetadata', 'editDraft', 'viewDraftHistory', 'viewAuditHistory', 'revokeContributor'))),
CONSTRAINT approach_assignments_recipient_check CHECK (
 (operation = 'create' AND initial_contributor_id IS NOT NULL) OR (operation <> 'create' AND initial_contributor_id IS NULL)),
CONSTRAINT approach_assignments_time_check CHECK (expires_at > starts_at AND {DURATION}),
CONSTRAINT approach_assignments_revocation_check CHECK (
 (revoked_at IS NULL AND revoked_by IS NULL) OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND revoked_at >= starts_at)),
CONSTRAINT approach_assignments_org_fk FOREIGN KEY (organization_id) REFERENCES organizations(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
CONSTRAINT approach_assignments_grantee_fk FOREIGN KEY (grantee_id, organization_id) REFERENCES users(id, organization_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
CONSTRAINT approach_assignments_issuer_fk FOREIGN KEY (issuer_id, organization_id) REFERENCES users(id, organization_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
CONSTRAINT approach_assignments_recipient_fk FOREIGN KEY (initial_contributor_id, organization_id) REFERENCES users(id, organization_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
CONSTRAINT approach_assignments_resource_fk FOREIGN KEY (organization_id, approach_id) REFERENCES approaches(organization_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
CONSTRAINT approach_assignments_revoker_fk FOREIGN KEY (revoked_by, organization_id) REFERENCES users(id, organization_id) ON UPDATE RESTRICT ON DELETE RESTRICT
{INDEX}
)
SQL;
        $statement = str_replace(['{ID}', '{FIXTURE}', '{DURATION}', '{INDEX}'], [$id, $fixture, $duration,
            $driver === 'mysql' ? ', INDEX approach_assignments_resource_idx (organization_id, fixture_id, approach_id, grantee_id, operation)' : ''], $sql);
        if ($driver === 'mysql') {
            DB::statement($statement.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
        } else {
            DB::transaction(function () use ($statement): void {
                DB::statement($statement);
                DB::statement('CREATE INDEX approach_assignments_resource_idx ON approach_assignments (organization_id, fixture_id, approach_id, grantee_id, operation)');
            });
        }
    }

    public function down(): void
    {
        foreach (['approach_assignments', 'approach_assignment_changes'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Assignment history preserved; populated rollback requires reviewed forward recovery.');
            }
        }
        Schema::dropIfExists('approach_assignments');
    }
};
