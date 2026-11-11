<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->guardTarget();
        if (Schema::hasTable('maturity_reassessment_attempts')) {
            throw new RuntimeException('Existing persistence table; reviewed recovery required before retry.');
        }
        if (DB::getDriverName() === 'sqlite') {
            (require __DIR__.'/2026_11_13_000001_add_reassessment_parent_key.php')->assertParents('maturity_reassessment_attempts');
        }
        $sql = DB::getDriverName() === 'mysql' ? <<<'MYSQL'
CREATE TABLE maturity_reassessment_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE,
 organization_id BIGINT UNSIGNED NOT NULL, fixture_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 approach_id BIGINT UNSIGNED NOT NULL, stage_id BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL,
 initial_assessment_id BIGINT UNSIGNED NOT NULL, previous_attempt_id BIGINT UNSIGNED NULL,
 attempt_number INT UNSIGNED NOT NULL,
 previous_attempt_number INT UNSIGNED GENERATED ALWAYS AS(CASE WHEN attempt_number>1 THEN attempt_number-1 ELSE NULL END) STORED,
 previous_approved_state VARCHAR(16) GENERATED ALWAYS AS('approved') STORED,
 author_id BIGINT UNSIGNED NOT NULL, configuration_id BIGINT UNSIGNED NOT NULL,
 approved_submission_id BIGINT UNSIGNED NULL, approved_review_id BIGINT UNSIGNED NULL,
 request_key CHAR(26) NOT NULL, request_hash CHAR(64) NOT NULL, state VARCHAR(16) NOT NULL, revision INT UNSIGNED NOT NULL,
 active_slot TINYINT UNSIGNED GENERATED ALWAYS AS(CASE WHEN state='open' THEN 1 ELSE NULL END) STORED,
 scenario VARCHAR(40) NOT NULL DEFAULT 'REVIEW_MATURITY_LOCAL_001', classification VARCHAR(16) NOT NULL DEFAULT 'TEST_ONLY',
 UNIQUE(organization_id,fixture_id,id),UNIQUE(organization_id,id), UNIQUE(organization_id,fixture_id,approach_id,stage_id,id),
 UNIQUE(organization_id,fixture_id,approach_id,stage_id,id,state),
 UNIQUE(organization_id,fixture_id,approach_id,stage_id,id,approved_submission_id,approved_review_id),
 UNIQUE(organization_id,fixture_id,approach_id,stage_id,attempt_number),
 UNIQUE(organization_id,fixture_id,approach_id,stage_id,id,attempt_number,state),
 UNIQUE(organization_id,fixture_id,approach_id,stage_id,active_slot),
 UNIQUE(organization_id,author_id,request_key),
 CHECK(classification='TEST_ONLY' AND scenario='REVIEW_MATURITY_LOCAL_001'),
 CHECK(CHAR_LENGTH(fixture_id)=32 AND fixture_id NOT REGEXP '[^0-9a-f]'),
 CHECK(revision>0 AND revision<=4294967295), CHECK(state IN ('open','approved')),
 CHECK((state='open' AND approved_submission_id IS NULL AND approved_review_id IS NULL) OR (state='approved' AND approved_submission_id IS NOT NULL AND approved_review_id IS NOT NULL)),
 CHECK(attempt_number>0 AND attempt_number<=4294967295),
 CHECK((attempt_number=1 AND previous_attempt_id IS NULL) OR (attempt_number>1 AND previous_attempt_id IS NOT NULL)),
 CHECK(CHAR_LENGTH(request_hash)=64 AND request_hash NOT REGEXP '[^0-9a-f]'),
 FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id,initial_assessment_id) REFERENCES maturity_workflow_assessments(organization_id,fixture_id,approach_id,stage_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id,previous_attempt_id,previous_attempt_number,previous_approved_state) REFERENCES maturity_reassessment_attempts(organization_id,fixture_id,approach_id,stage_id,id,attempt_number,state) ON DELETE RESTRICT ON UPDATE RESTRICT,
 FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id,configuration_id) REFERENCES maturity_workflow_configurations(organization_id,fixture_id,approach_id,stage_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 FOREIGN KEY(author_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
MYSQL
        : <<<'SQLITE'
CREATE TABLE maturity_reassessment_attempts (
 id INTEGER PRIMARY KEY AUTOINCREMENT, public_id CHAR(26) NOT NULL UNIQUE,
 organization_id BIGINT UNSIGNED NOT NULL, fixture_id CHAR(32) NOT NULL,
 approach_id BIGINT UNSIGNED NOT NULL, stage_id BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL,
 initial_assessment_id BIGINT UNSIGNED NOT NULL, previous_attempt_id BIGINT UNSIGNED NULL,
 attempt_number INT UNSIGNED NOT NULL,
 previous_attempt_number INT UNSIGNED GENERATED ALWAYS AS(CASE WHEN attempt_number>1 THEN attempt_number-1 ELSE NULL END) STORED,
 previous_approved_state VARCHAR(16) GENERATED ALWAYS AS('approved') STORED,
 author_id BIGINT UNSIGNED NOT NULL, configuration_id BIGINT UNSIGNED NOT NULL,
 approved_submission_id BIGINT UNSIGNED NULL, approved_review_id BIGINT UNSIGNED NULL,
 request_key CHAR(26) NOT NULL, request_hash CHAR(64) NOT NULL, state VARCHAR(16) NOT NULL, revision INT UNSIGNED NOT NULL,
 active_slot TINYINT UNSIGNED GENERATED ALWAYS AS(CASE WHEN state='open' THEN 1 ELSE NULL END) STORED,
 scenario VARCHAR(40) NOT NULL DEFAULT 'REVIEW_MATURITY_LOCAL_001', classification VARCHAR(16) NOT NULL DEFAULT 'TEST_ONLY',
 UNIQUE(organization_id,fixture_id,id),UNIQUE(organization_id,id), UNIQUE(organization_id,fixture_id,approach_id,stage_id,id),
 UNIQUE(organization_id,fixture_id,approach_id,stage_id,id,state),
 UNIQUE(organization_id,fixture_id,approach_id,stage_id,id,approved_submission_id,approved_review_id),
 UNIQUE(organization_id,fixture_id,approach_id,stage_id,attempt_number),
 UNIQUE(organization_id,fixture_id,approach_id,stage_id,id,attempt_number,state),
 UNIQUE(organization_id,fixture_id,approach_id,stage_id,active_slot),
 UNIQUE(organization_id,author_id,request_key),
 CHECK(classification='TEST_ONLY' AND scenario='REVIEW_MATURITY_LOCAL_001'),
 CHECK(length(fixture_id)=32 AND fixture_id NOT GLOB '*[^0-9a-f]*'),
 CHECK(revision>0 AND revision<=4294967295), CHECK(state IN ('open','approved')),
 CHECK((state='open' AND approved_submission_id IS NULL AND approved_review_id IS NULL) OR (state='approved' AND approved_submission_id IS NOT NULL AND approved_review_id IS NOT NULL)),
 CHECK(attempt_number>0 AND attempt_number<=4294967295),
 CHECK((attempt_number=1 AND previous_attempt_id IS NULL) OR (attempt_number>1 AND previous_attempt_id IS NOT NULL)),
 CHECK(length(request_hash)=64 AND request_hash NOT GLOB '*[^0-9a-f]*'),
 FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id,initial_assessment_id) REFERENCES maturity_workflow_assessments(organization_id,fixture_id,approach_id,stage_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id,previous_attempt_id,previous_attempt_number,previous_approved_state) REFERENCES maturity_reassessment_attempts(organization_id,fixture_id,approach_id,stage_id,id,attempt_number,state) ON DELETE RESTRICT ON UPDATE RESTRICT,
 FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id,configuration_id) REFERENCES maturity_workflow_configurations(organization_id,fixture_id,approach_id,stage_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 FOREIGN KEY(author_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT
);
SQLITE;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        $this->guardRollback();
        Schema::drop('maturity_reassessment_attempts');
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
