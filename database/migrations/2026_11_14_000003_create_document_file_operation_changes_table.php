<?php

use App\Support\ClosureMigrationGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        ClosureMigrationGuards::assertTarget();
        if (Schema::hasTable('document_file_operation_changes')) {
            throw new RuntimeException('Existing closure operation table; no adoption or destructive retry.');
        }
        $sql = DB::getDriverName() === 'mysql' ? <<<'MYSQL'
CREATE TABLE document_file_operation_changes (
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE,
 organization_id BIGINT UNSIGNED NOT NULL, fixture_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 scenario VARCHAR(40) NOT NULL DEFAULT 'DOCUMENT_LOCAL_001', classification VARCHAR(16) NOT NULL DEFAULT 'TEST_ONLY',
 created_at DATETIME NOT NULL,
 assignment_id BIGINT UNSIGNED NULL, actor_id BIGINT UNSIGNED NOT NULL, subject_id BIGINT UNSIGNED NOT NULL,
 event VARCHAR(24) NOT NULL, event_key VARCHAR(100) NOT NULL, details_json JSON NOT NULL,
 CONSTRAINT df_change_tenant UNIQUE(organization_id,id),
 CONSTRAINT df_change_event UNIQUE(organization_id,fixture_id,event_key),
 CONSTRAINT df_change_class CHECK(classification='TEST_ONLY' AND scenario='DOCUMENT_LOCAL_001'),
 CONSTRAINT df_change_fixture CHECK(CHAR_LENGTH(fixture_id)=32 AND fixture_id NOT REGEXP '[^0-9a-f]'),
 CONSTRAINT df_change_kind CHECK(event IN('granted','revoked','expiration_observed','access_denied')),
 CONSTRAINT df_change_reference CHECK(assignment_id IS NOT NULL OR event='access_denied'),
 CONSTRAINT df_change_details CHECK(JSON_TYPE(details_json)='OBJECT'),
 CONSTRAINT df_change_uint CHECK(organization_id>0 AND actor_id>0 AND subject_id>0 AND (assignment_id IS NULL OR assignment_id>0)),
 CONSTRAINT df_change_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT df_change_actor FOREIGN KEY(actor_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT df_change_subject FOREIGN KEY(subject_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT df_change_grant FOREIGN KEY(organization_id,fixture_id,assignment_id,subject_id) REFERENCES document_file_operation_assignments(organization_id,fixture_id,id,grantee_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
MYSQL
        : <<<'SQLITE'
CREATE TABLE document_file_operation_changes (
id INTEGER PRIMARY KEY AUTOINCREMENT, public_id CHAR(26) NOT NULL UNIQUE,
 organization_id BIGINT UNSIGNED NOT NULL, fixture_id CHAR(32) NOT NULL,
 scenario VARCHAR(40) NOT NULL DEFAULT 'DOCUMENT_LOCAL_001', classification VARCHAR(16) NOT NULL DEFAULT 'TEST_ONLY',
 created_at DATETIME NOT NULL,
 assignment_id BIGINT UNSIGNED NULL, actor_id BIGINT UNSIGNED NOT NULL, subject_id BIGINT UNSIGNED NOT NULL,
 event VARCHAR(24) NOT NULL, event_key VARCHAR(100) NOT NULL, details_json TEXT NOT NULL,
 CONSTRAINT df_change_tenant UNIQUE(organization_id,id),
 CONSTRAINT df_change_event UNIQUE(organization_id,fixture_id,event_key),
 CONSTRAINT df_change_class CHECK(classification='TEST_ONLY' AND scenario='DOCUMENT_LOCAL_001'),
 CONSTRAINT df_change_fixture CHECK(length(fixture_id)=32 AND fixture_id NOT GLOB '*[^0-9a-f]*'),
 CONSTRAINT df_change_kind CHECK(event IN('granted','revoked','expiration_observed','access_denied')),
 CONSTRAINT df_change_reference CHECK(assignment_id IS NOT NULL OR event='access_denied'),
 CONSTRAINT df_change_details CHECK(json_valid(details_json) AND json_type(details_json)='object'),
 CONSTRAINT df_change_uint CHECK(id>0 AND organization_id>0 AND actor_id>0 AND subject_id>0 AND (assignment_id IS NULL OR assignment_id>0)),
 CONSTRAINT df_change_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT df_change_actor FOREIGN KEY(actor_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT df_change_subject FOREIGN KEY(subject_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT df_change_grant FOREIGN KEY(organization_id,fixture_id,assignment_id,subject_id) REFERENCES document_file_operation_assignments(organization_id,fixture_id,id,grantee_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQLITE;
        DB::statement($sql);
    }

    public function down(): void
    {
        ClosureMigrationGuards::assertRollbackSafe();
        Schema::drop('document_file_operation_changes');
    }
};
