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
        if (Schema::hasTable('document_file_operation_assignments')) {
            throw new RuntimeException('Existing closure operation table; no adoption or destructive retry.');
        }
        $sql = DB::getDriverName() === 'mysql' ? <<<'MYSQL'
CREATE TABLE document_file_operation_assignments (
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE,
 organization_id BIGINT UNSIGNED NOT NULL, fixture_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 scenario VARCHAR(40) NOT NULL DEFAULT 'DOCUMENT_LOCAL_001', classification VARCHAR(16) NOT NULL DEFAULT 'TEST_ONLY',
 created_at DATETIME NOT NULL,
 grantee_id BIGINT UNSIGNED NOT NULL, issuer_id BIGINT UNSIGNED NOT NULL,
 document_public_id CHAR(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, version_public_id CHAR(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
 document_id BIGINT UNSIGNED NULL, version_id BIGINT UNSIGNED NULL, operation VARCHAR(32) NOT NULL,
 starts_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, revoked_at DATETIME NULL, revoked_by BIGINT UNSIGNED NULL,
 version_scope CHAR(26) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS(COALESCE(version_public_id,'00000000000000000000000000')) STORED,
 CONSTRAINT df_grant_tenant UNIQUE(organization_id,id),
 CONSTRAINT df_grant_identity UNIQUE(organization_id,fixture_id,id,grantee_id),
 CONSTRAINT df_grant_once UNIQUE(organization_id,fixture_id,grantee_id,operation,document_public_id,version_scope),
 CONSTRAINT df_grant_class CHECK(classification='TEST_ONLY' AND scenario='DOCUMENT_LOCAL_001'),
 CONSTRAINT df_grant_fixture CHECK(CHAR_LENGTH(fixture_id)=32 AND fixture_id NOT REGEXP '[^0-9a-f]'),
 CONSTRAINT df_grant_scope CHECK(
 (operation IN('view','metadata','history') AND version_public_id IS NULL AND version_id IS NULL) OR
 (operation IN('upload_real','append_real','release_real','download_real','release_demo_unscanned','download_demo_unscanned') AND version_public_id IS NOT NULL)),
 CONSTRAINT df_grant_version_parent CHECK(version_id IS NULL OR document_id IS NOT NULL),
 CONSTRAINT df_grant_uint CHECK(organization_id>0 AND grantee_id>0 AND issuer_id>0 AND (document_id IS NULL OR document_id>0) AND (version_id IS NULL OR version_id>0) AND (revoked_by IS NULL OR revoked_by>0)),
 CONSTRAINT df_grant_doc FOREIGN KEY(organization_id,document_id,document_public_id) REFERENCES documents(organization_id,id,public_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT df_grant_version FOREIGN KEY(organization_id,document_id,version_id,version_public_id) REFERENCES document_versions(organization_id,document_id,id,public_id) ON DELETE RESTRICT ON UPDATE RESTRICT,

 CONSTRAINT df_grant_period CHECK(DATE_ADD(starts_at,INTERVAL 24 HOUR) IS NOT NULL AND expires_at>starts_at AND expires_at=DATE_ADD(starts_at,INTERVAL 24 HOUR)),
 CONSTRAINT df_grant_revocation CHECK((revoked_at IS NULL AND revoked_by IS NULL) OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND revoked_at>=starts_at)),
 CONSTRAINT df_grant_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT df_grant_grantee FOREIGN KEY(grantee_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT df_grant_issuer FOREIGN KEY(issuer_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT df_grant_revoker FOREIGN KEY(revoked_by,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
MYSQL
        : <<<'SQLITE'
CREATE TABLE document_file_operation_assignments (
id INTEGER PRIMARY KEY AUTOINCREMENT, public_id CHAR(26) NOT NULL UNIQUE,
 organization_id BIGINT UNSIGNED NOT NULL, fixture_id CHAR(32) NOT NULL,
 scenario VARCHAR(40) NOT NULL DEFAULT 'DOCUMENT_LOCAL_001', classification VARCHAR(16) NOT NULL DEFAULT 'TEST_ONLY',
 created_at DATETIME NOT NULL,
 grantee_id BIGINT UNSIGNED NOT NULL, issuer_id BIGINT UNSIGNED NOT NULL,
 document_public_id CHAR(26)  NOT NULL, version_public_id CHAR(26)  NULL,
 document_id BIGINT UNSIGNED NULL, version_id BIGINT UNSIGNED NULL, operation VARCHAR(32) NOT NULL,
 starts_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, revoked_at DATETIME NULL, revoked_by BIGINT UNSIGNED NULL,
 version_scope CHAR(26)  GENERATED ALWAYS AS(COALESCE(version_public_id,'00000000000000000000000000')) STORED,
 CONSTRAINT df_grant_tenant UNIQUE(organization_id,id),
 CONSTRAINT df_grant_identity UNIQUE(organization_id,fixture_id,id,grantee_id),
 CONSTRAINT df_grant_once UNIQUE(organization_id,fixture_id,grantee_id,operation,document_public_id,version_scope),
 CONSTRAINT df_grant_class CHECK(classification='TEST_ONLY' AND scenario='DOCUMENT_LOCAL_001'),
 CONSTRAINT df_grant_fixture CHECK(length(fixture_id)=32 AND fixture_id NOT GLOB '*[^0-9a-f]*'),
 CONSTRAINT df_grant_scope CHECK(
 (operation IN('view','metadata','history') AND version_public_id IS NULL AND version_id IS NULL) OR
 (operation IN('upload_real','append_real','release_real','download_real','release_demo_unscanned','download_demo_unscanned') AND version_public_id IS NOT NULL)),
 CONSTRAINT df_grant_version_parent CHECK(version_id IS NULL OR document_id IS NOT NULL),
 CONSTRAINT df_grant_uint CHECK(id>0 AND organization_id>0 AND grantee_id>0 AND issuer_id>0 AND (document_id IS NULL OR document_id>0) AND (version_id IS NULL OR version_id>0) AND (revoked_by IS NULL OR revoked_by>0)),
 CONSTRAINT df_grant_doc FOREIGN KEY(organization_id,document_id,document_public_id) REFERENCES documents(organization_id,id,public_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT df_grant_version FOREIGN KEY(organization_id,document_id,version_id,version_public_id) REFERENCES document_versions(organization_id,document_id,id,public_id) ON DELETE RESTRICT ON UPDATE RESTRICT,

 CONSTRAINT df_grant_period CHECK(datetime(starts_at,'+24 hours') IS NOT NULL AND expires_at>starts_at AND expires_at=datetime(starts_at,'+24 hours')),
 CONSTRAINT df_grant_revocation CHECK((revoked_at IS NULL AND revoked_by IS NULL) OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND revoked_at>=starts_at)),
 CONSTRAINT df_grant_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT df_grant_grantee FOREIGN KEY(grantee_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT df_grant_issuer FOREIGN KEY(issuer_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT df_grant_revoker FOREIGN KEY(revoked_by,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQLITE;
        DB::statement($sql);
    }

    public function down(): void
    {
        ClosureMigrationGuards::assertRollbackSafe();
        Schema::drop('document_file_operation_assignments');
    }
};
