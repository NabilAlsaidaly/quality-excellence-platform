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
        if (Schema::hasTable('maturity_reassessment_operation_assignments')) {
            throw new RuntimeException('Existing closure operation table; no adoption or destructive retry.');
        }
        $sql = DB::getDriverName() === 'mysql' ? <<<'MYSQL'
CREATE TABLE maturity_reassessment_operation_assignments (
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE,
 organization_id BIGINT UNSIGNED NOT NULL, fixture_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 scenario VARCHAR(40) NOT NULL DEFAULT 'REVIEW_MATURITY_LOCAL_001', classification VARCHAR(16) NOT NULL DEFAULT 'TEST_ONLY',
 created_at DATETIME NOT NULL,
 approach_id BIGINT UNSIGNED NOT NULL, stage_id BIGINT UNSIGNED NOT NULL,
 grantee_id BIGINT UNSIGNED NOT NULL, issuer_id BIGINT UNSIGNED NOT NULL, operation VARCHAR(24) NOT NULL,
 starts_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, revoked_at DATETIME NULL, revoked_by BIGINT UNSIGNED NULL,
 CONSTRAINT ra_grant_tenant UNIQUE(organization_id,id),
 CONSTRAINT ra_grant_identity UNIQUE(organization_id,fixture_id,id,grantee_id),
 CONSTRAINT ra_grant_once UNIQUE(organization_id,fixture_id,approach_id,stage_id,grantee_id,operation),
 CONSTRAINT ra_grant_class CHECK(classification='TEST_ONLY' AND scenario='REVIEW_MATURITY_LOCAL_001'),
 CONSTRAINT ra_grant_fixture CHECK(CHAR_LENGTH(fixture_id)=32 AND fixture_id NOT REGEXP '[^0-9a-f]'),
 CONSTRAINT ra_grant_operation CHECK(operation IN('open_reassessment','save_reassessment','review_reassessment','history_reassessment')),
 CONSTRAINT ra_grant_uint CHECK(organization_id>0 AND approach_id>0 AND stage_id>0 AND grantee_id>0 AND issuer_id>0 AND (revoked_by IS NULL OR revoked_by>0)),
 CONSTRAINT ra_grant_stage FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id) REFERENCES maturity_workflow_stages(organization_id,fixture_id,approach_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,

 CONSTRAINT ra_grant_period CHECK(DATE_ADD(starts_at,INTERVAL 24 HOUR) IS NOT NULL AND expires_at>starts_at AND expires_at=DATE_ADD(starts_at,INTERVAL 24 HOUR)),
 CONSTRAINT ra_grant_revocation CHECK((revoked_at IS NULL AND revoked_by IS NULL) OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND revoked_at>=starts_at)),
 CONSTRAINT ra_grant_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT ra_grant_grantee FOREIGN KEY(grantee_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT ra_grant_issuer FOREIGN KEY(issuer_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT ra_grant_revoker FOREIGN KEY(revoked_by,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
MYSQL
        : <<<'SQLITE'
CREATE TABLE maturity_reassessment_operation_assignments (
id INTEGER PRIMARY KEY AUTOINCREMENT, public_id CHAR(26) NOT NULL UNIQUE,
 organization_id BIGINT UNSIGNED NOT NULL, fixture_id CHAR(32) NOT NULL,
 scenario VARCHAR(40) NOT NULL DEFAULT 'REVIEW_MATURITY_LOCAL_001', classification VARCHAR(16) NOT NULL DEFAULT 'TEST_ONLY',
 created_at DATETIME NOT NULL,
 approach_id BIGINT UNSIGNED NOT NULL, stage_id BIGINT UNSIGNED NOT NULL,
 grantee_id BIGINT UNSIGNED NOT NULL, issuer_id BIGINT UNSIGNED NOT NULL, operation VARCHAR(24) NOT NULL,
 starts_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, revoked_at DATETIME NULL, revoked_by BIGINT UNSIGNED NULL,
 CONSTRAINT ra_grant_tenant UNIQUE(organization_id,id),
 CONSTRAINT ra_grant_identity UNIQUE(organization_id,fixture_id,id,grantee_id),
 CONSTRAINT ra_grant_once UNIQUE(organization_id,fixture_id,approach_id,stage_id,grantee_id,operation),
 CONSTRAINT ra_grant_class CHECK(classification='TEST_ONLY' AND scenario='REVIEW_MATURITY_LOCAL_001'),
 CONSTRAINT ra_grant_fixture CHECK(length(fixture_id)=32 AND fixture_id NOT GLOB '*[^0-9a-f]*'),
 CONSTRAINT ra_grant_operation CHECK(operation IN('open_reassessment','save_reassessment','review_reassessment','history_reassessment')),
 CONSTRAINT ra_grant_uint CHECK(id>0 AND organization_id>0 AND approach_id>0 AND stage_id>0 AND grantee_id>0 AND issuer_id>0 AND (revoked_by IS NULL OR revoked_by>0)),
 CONSTRAINT ra_grant_stage FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id) REFERENCES maturity_workflow_stages(organization_id,fixture_id,approach_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,

 CONSTRAINT ra_grant_period CHECK(datetime(starts_at,'+24 hours') IS NOT NULL AND expires_at>starts_at AND expires_at=datetime(starts_at,'+24 hours')),
 CONSTRAINT ra_grant_revocation CHECK((revoked_at IS NULL AND revoked_by IS NULL) OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND revoked_at>=starts_at)),
 CONSTRAINT ra_grant_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT ra_grant_grantee FOREIGN KEY(grantee_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT ra_grant_issuer FOREIGN KEY(issuer_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT ra_grant_revoker FOREIGN KEY(revoked_by,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQLITE;
        DB::statement($sql);
    }

    public function down(): void
    {
        ClosureMigrationGuards::assertRollbackSafe();
        Schema::drop('maturity_reassessment_operation_assignments');
    }
};
