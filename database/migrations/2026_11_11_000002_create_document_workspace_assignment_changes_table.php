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
            throw new RuntimeException('Document workspace audit supports verified MySQL/SQLite only.');
        }
        $id = $driver === 'mysql' ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $fixtureType = $driver === 'mysql' ? 'CHAR(32) CHARACTER SET ascii COLLATE ascii_bin' : 'CHAR(32)';
        $fixture = $driver === 'mysql' ? "CHAR_LENGTH(fixture_id)=32 AND fixture_id NOT REGEXP '[^0-9a-f]'" : "length(fixture_id)=32 AND fixture_id NOT GLOB '*[^0-9a-f]*'";
        $sql = <<<'SQL'
CREATE TABLE document_workspace_assignment_changes (
 id {ID}, public_id CHAR(26) NOT NULL,
 organization_id BIGINT UNSIGNED NOT NULL, fixture_id {FIXTURE_TYPE} NOT NULL,
 scenario VARCHAR(40) NOT NULL DEFAULT 'DOCUMENT_LOCAL_001',
 classification VARCHAR(16) NOT NULL DEFAULT 'TEST_ONLY',
 assignment_id BIGINT UNSIGNED NULL, document_id BIGINT UNSIGNED NULL,
 actor_id BIGINT UNSIGNED NOT NULL, subject_id BIGINT UNSIGNED NOT NULL,
 event VARCHAR(24) NOT NULL, operation VARCHAR(20) NOT NULL, event_key VARCHAR(100) NOT NULL,
 created_at DATETIME NOT NULL,
 scope_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(document_id,0)) STORED,
 CONSTRAINT doc_ws_change_public UNIQUE (public_id),
 CONSTRAINT doc_ws_change_tenant UNIQUE (organization_id,id),
 CONSTRAINT doc_ws_change_event UNIQUE (organization_id,fixture_id,event_key),
 CONSTRAINT doc_ws_change_class CHECK (classification='TEST_ONLY' AND scenario='DOCUMENT_LOCAL_001'),
 CONSTRAINT doc_ws_change_fixture CHECK ({FIXTURE}),
 CONSTRAINT doc_ws_change_kind CHECK (event IN ('granted','revoked','expiration_observed','access_denied')),
 CONSTRAINT doc_ws_change_reference CHECK (assignment_id IS NOT NULL OR event='access_denied'),
 CONSTRAINT doc_ws_change_scope CHECK (
   (document_id IS NULL AND operation IN ('create','list')) OR
   (document_id IS NOT NULL AND operation IN ('view','metadata','append','history'))),
 CONSTRAINT doc_ws_change_org_fk FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT doc_ws_change_actor_fk FOREIGN KEY (actor_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT doc_ws_change_subject_fk FOREIGN KEY (subject_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT doc_ws_change_document_fk FOREIGN KEY (organization_id,document_id) REFERENCES documents(organization_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT doc_ws_change_assignment_fk FOREIGN KEY (organization_id,fixture_id,assignment_id,subject_id,scope_key,operation)
   REFERENCES document_workspace_assignments(organization_id,fixture_id,id,grantee_id,scope_key,operation) ON DELETE RESTRICT ON UPDATE RESTRICT
 {INDEX}
)
SQL;
        $statement = str_replace(['{ID}', '{FIXTURE_TYPE}', '{FIXTURE}', '{INDEX}'], [$id, $fixtureType, $fixture,
            $driver === 'mysql' ? ', INDEX doc_ws_change_history_idx (organization_id,fixture_id,document_id,id)' : ''], $sql);
        if ($driver === 'mysql') {
            DB::statement($statement.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
        } else {
            DB::transaction(function () use ($statement): void {
                DB::statement($statement);
                DB::statement('CREATE INDEX doc_ws_change_history_idx ON document_workspace_assignment_changes (organization_id,fixture_id,document_id,id)');
            });
        }
    }

    public function down(): void
    {
        foreach (['document_workspace_assignments', 'document_workspace_assignment_changes', 'document_metadata_revisions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Document workspace history preserved before any DROP; use reviewed forward recovery.');
            }
        }
        Schema::dropIfExists('document_workspace_assignment_changes');
    }
};
