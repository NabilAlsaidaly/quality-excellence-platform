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
            throw new RuntimeException('Assignment audit supports verified MySQL/SQLite only.');
        }
        $id = $driver === 'mysql' ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $fixture = $driver === 'mysql' ? "CHAR_LENGTH(fixture_id) = 32 AND fixture_id NOT REGEXP '[^0-9a-f]'" : "length(fixture_id) = 32 AND fixture_id NOT GLOB '*[^0-9a-f]*'";
        $sql = <<<'SQL'
CREATE TABLE approach_assignment_changes (
id {ID}, public_id CHAR(26) NOT NULL UNIQUE,
organization_id BIGINT UNSIGNED NOT NULL, fixture_id CHAR(32) NOT NULL,
classification VARCHAR(12) NOT NULL DEFAULT 'TEST_ONLY', scenario VARCHAR(32) NOT NULL DEFAULT 'J03_LOCAL_APPROACH_001',
assignment_id BIGINT UNSIGNED NULL, approach_id BIGINT UNSIGNED NULL,
actor_id BIGINT UNSIGNED NOT NULL, subject_id BIGINT UNSIGNED NOT NULL,
event VARCHAR(24) NOT NULL, operation VARCHAR(32) NOT NULL, event_key VARCHAR(64) NOT NULL,
created_at DATETIME NOT NULL,
CONSTRAINT approach_assignment_changes_key UNIQUE (organization_id, fixture_id, event_key),
CONSTRAINT approach_assignment_changes_test_check CHECK (classification = 'TEST_ONLY' AND scenario = 'J03_LOCAL_APPROACH_001' AND {FIXTURE}),
CONSTRAINT approach_assignment_changes_event_check CHECK (event IN ('granted', 'revoked', 'expiration_observed', 'access_denied')),
CONSTRAINT approach_assignment_changes_reference_check CHECK (event = 'access_denied' OR assignment_id IS NOT NULL),
CONSTRAINT approach_assignment_changes_operation_check CHECK (operation IN ('quality', 'create', 'list', 'view', 'updateMetadata', 'editDraft', 'viewDraftHistory', 'viewAuditHistory', 'revokeContributor')),
CONSTRAINT approach_assignment_changes_org_fk FOREIGN KEY (organization_id) REFERENCES organizations(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
CONSTRAINT approach_assignment_changes_assignment_fk FOREIGN KEY (organization_id, fixture_id, assignment_id, subject_id) REFERENCES approach_assignments(organization_id, fixture_id, id, grantee_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
CONSTRAINT approach_assignment_changes_resource_fk FOREIGN KEY (organization_id, approach_id) REFERENCES approaches(organization_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
CONSTRAINT approach_assignment_changes_actor_fk FOREIGN KEY (actor_id, organization_id) REFERENCES users(id, organization_id) ON UPDATE RESTRICT ON DELETE RESTRICT,
CONSTRAINT approach_assignment_changes_subject_fk FOREIGN KEY (subject_id, organization_id) REFERENCES users(id, organization_id) ON UPDATE RESTRICT ON DELETE RESTRICT
{INDEX}
)
SQL;
        $statement = str_replace(['{ID}', '{FIXTURE}', '{INDEX}'], [$id, $fixture,
            $driver === 'mysql' ? ', INDEX approach_assignment_changes_history_idx (organization_id, fixture_id, approach_id, id)' : ''], $sql);
        if ($driver === 'mysql') {
            DB::statement($statement.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
        } else {
            DB::transaction(function () use ($statement): void {
                DB::statement($statement);
                DB::statement('CREATE INDEX approach_assignment_changes_history_idx ON approach_assignment_changes (organization_id, fixture_id, approach_id, id)');
            });
        }
    }

    public function down(): void
    {
        foreach (['approach_assignments', 'approach_assignment_changes'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Assignment history preserved before first DROP; use reviewed forward recovery.');
            }
        }
        Schema::dropIfExists('approach_assignment_changes');
    }
};
