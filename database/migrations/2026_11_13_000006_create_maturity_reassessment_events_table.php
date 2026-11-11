<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->guardTarget();
        if (Schema::hasTable('maturity_reassessment_events')) {
            throw new RuntimeException('Existing persistence table; reviewed recovery required before retry.');
        }
        if (DB::getDriverName() === 'sqlite') {
            (require __DIR__.'/2026_11_13_000001_add_reassessment_parent_key.php')->assertParents('maturity_reassessment_events');
        }
        $sql = DB::getDriverName() === 'mysql' ? <<<'MYSQL'
CREATE TABLE maturity_reassessment_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE,
 organization_id BIGINT UNSIGNED NOT NULL, fixture_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 approach_id BIGINT UNSIGNED NOT NULL, stage_id BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL,
 attempt_id BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NOT NULL, submission_id BIGINT UNSIGNED NULL,
 request_key CHAR(26) NOT NULL, event VARCHAR(24) NOT NULL, details_json JSON NOT NULL,
 scenario VARCHAR(40) NOT NULL DEFAULT 'REVIEW_MATURITY_LOCAL_001', classification VARCHAR(16) NOT NULL DEFAULT 'TEST_ONLY',
 UNIQUE(organization_id,fixture_id,id),UNIQUE(organization_id,id), UNIQUE(organization_id,fixture_id,approach_id,stage_id,attempt_id,request_key),
 CHECK(classification='TEST_ONLY' AND scenario='REVIEW_MATURITY_LOCAL_001'),
 CHECK(CHAR_LENGTH(fixture_id)=32 AND fixture_id NOT REGEXP '[^0-9a-f]'),
 CHECK(event IN ('opened','saved','changes_requested','approved','access_denied')), CHECK(JSON_TYPE(details_json)='OBJECT'),
 FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id,attempt_id) REFERENCES maturity_reassessment_attempts(organization_id,fixture_id,approach_id,stage_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id,submission_id) REFERENCES maturity_workflow_submissions(organization_id,fixture_id,approach_id,stage_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 FOREIGN KEY(actor_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
MYSQL
        : <<<'SQLITE'
CREATE TABLE maturity_reassessment_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT, public_id CHAR(26) NOT NULL UNIQUE,
 organization_id BIGINT UNSIGNED NOT NULL, fixture_id CHAR(32) NOT NULL,
 approach_id BIGINT UNSIGNED NOT NULL, stage_id BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL,
 attempt_id BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NOT NULL, submission_id BIGINT UNSIGNED NULL,
 request_key CHAR(26) NOT NULL, event VARCHAR(24) NOT NULL, details_json JSON NOT NULL,
 scenario VARCHAR(40) NOT NULL DEFAULT 'REVIEW_MATURITY_LOCAL_001', classification VARCHAR(16) NOT NULL DEFAULT 'TEST_ONLY',
 UNIQUE(organization_id,fixture_id,id),UNIQUE(organization_id,id), UNIQUE(organization_id,fixture_id,approach_id,stage_id,attempt_id,request_key),
 CHECK(classification='TEST_ONLY' AND scenario='REVIEW_MATURITY_LOCAL_001'),
 CHECK(length(fixture_id)=32 AND fixture_id NOT GLOB '*[^0-9a-f]*'),
 CHECK(event IN ('opened','saved','changes_requested','approved','access_denied')), CHECK(json_type(details_json)='object'),
 FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id,attempt_id) REFERENCES maturity_reassessment_attempts(organization_id,fixture_id,approach_id,stage_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id,submission_id) REFERENCES maturity_workflow_submissions(organization_id,fixture_id,approach_id,stage_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 FOREIGN KEY(actor_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT
);
SQLITE;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        $this->guardRollback();
        Schema::drop('maturity_reassessment_events');
    }

    private function guardTarget(): void
    {
        (require __DIR__.'/2026_11_13_000001_add_reassessment_parent_key.php')->assertTarget();
    }

    private function guardRollback(): void
    {
        // One package-local guard preflights every child before any reverse DDL.
        (require __DIR__.'/2026_11_13_000001_add_reassessment_parent_key.php')->assertRollbackSafe();
    }
};
