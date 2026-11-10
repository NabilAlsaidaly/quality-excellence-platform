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
            throw new RuntimeException('Document workspace supports verified MySQL/SQLite only.');
        }
        $id = $driver === 'mysql' ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $fixtureType = $driver === 'mysql' ? 'CHAR(32) CHARACTER SET ascii COLLATE ascii_bin' : 'CHAR(32)';
        $fixture = $driver === 'mysql' ? "CHAR_LENGTH(fixture_id)=32 AND fixture_id NOT REGEXP '[^0-9a-f]'" : "length(fixture_id)=32 AND fixture_id NOT GLOB '*[^0-9a-f]*'";
        $end = $driver === 'mysql' ? 'DATE_ADD(starts_at, INTERVAL 24 HOUR)' : "datetime(starts_at, '+24 hours')";
        $sql = <<<'SQL'
CREATE TABLE document_workspace_assignments (
 id {ID}, public_id CHAR(26) NOT NULL,
 organization_id BIGINT UNSIGNED NOT NULL, fixture_id {FIXTURE_TYPE} NOT NULL,
 scenario VARCHAR(40) NOT NULL DEFAULT 'DOCUMENT_LOCAL_001',
 classification VARCHAR(16) NOT NULL DEFAULT 'TEST_ONLY',
 grantee_id BIGINT UNSIGNED NOT NULL, issuer_id BIGINT UNSIGNED NOT NULL,
 document_id BIGINT UNSIGNED NULL, scope_kind VARCHAR(16) NOT NULL, operation VARCHAR(20) NOT NULL,
 starts_at DATETIME NOT NULL, expires_at DATETIME NOT NULL,
 revoked_at DATETIME NULL, revoked_by BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL,
 scope_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(document_id,0)) STORED,
 CONSTRAINT doc_ws_assignment_public UNIQUE (public_id),
 CONSTRAINT doc_ws_assignment_tenant UNIQUE (organization_id,id),
 CONSTRAINT doc_ws_assignment_operation UNIQUE (organization_id,fixture_id,grantee_id,operation,scope_key),
 CONSTRAINT doc_ws_assignment_audit_identity UNIQUE (organization_id,fixture_id,id,grantee_id,scope_key,operation),
 CONSTRAINT doc_ws_assignment_class CHECK (classification='TEST_ONLY' AND scenario='DOCUMENT_LOCAL_001'),
 CONSTRAINT doc_ws_assignment_fixture CHECK ({FIXTURE}),
 CONSTRAINT doc_ws_assignment_scope CHECK (
   (scope_kind='institution' AND document_id IS NULL AND operation IN ('create','list')) OR
   (scope_kind='document' AND document_id IS NOT NULL AND operation IN ('view','metadata','append','history'))),
 CONSTRAINT doc_ws_assignment_period CHECK ({END} IS NOT NULL AND expires_at>starts_at AND expires_at={END}),
 CONSTRAINT doc_ws_assignment_revoke CHECK (
   (revoked_at IS NULL AND revoked_by IS NULL) OR
   (revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND revoked_at>=starts_at)),
 CONSTRAINT doc_ws_assignment_org_fk FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT doc_ws_assignment_grantee_fk FOREIGN KEY (grantee_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT doc_ws_assignment_issuer_fk FOREIGN KEY (issuer_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT doc_ws_assignment_revoker_fk FOREIGN KEY (revoked_by,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT doc_ws_assignment_document_fk FOREIGN KEY (organization_id,document_id) REFERENCES documents(organization_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT
 {INDEX}
)
SQL;
        $statement = str_replace(['{ID}', '{FIXTURE_TYPE}', '{FIXTURE}', '{END}', '{INDEX}'], [$id, $fixtureType, $fixture, $end,
            $driver === 'mysql' ? ', INDEX doc_ws_assignment_resource_idx (organization_id,fixture_id,document_id,grantee_id,operation)' : ''], $sql);
        if ($driver === 'mysql') {
            DB::statement($statement.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
        } else {
            DB::transaction(function () use ($statement): void {
                DB::statement($statement);
                DB::statement('CREATE INDEX doc_ws_assignment_resource_idx ON document_workspace_assignments (organization_id,fixture_id,document_id,grantee_id,operation)');
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
        Schema::dropIfExists('document_workspace_assignments');
    }
};
