<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->guardTarget();
        if (Schema::hasTable('maturity_reassessment_decisions')) {
            throw new RuntimeException('Existing persistence table; reviewed recovery required before retry.');
        }
        if (DB::getDriverName() === 'sqlite') {
            (require __DIR__.'/2026_11_13_000001_add_reassessment_parent_key.php')->assertParents('maturity_reassessment_decisions');
        }
        $sql = DB::getDriverName() === 'mysql' ? <<<'MYSQL'
CREATE TABLE maturity_reassessment_decisions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE,
 organization_id BIGINT UNSIGNED NOT NULL, fixture_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 approach_id BIGINT UNSIGNED NOT NULL, stage_id BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL,
 attempt_id BIGINT UNSIGNED NOT NULL, submission_id BIGINT UNSIGNED NOT NULL, review_id BIGINT UNSIGNED NOT NULL,
 reviewer_id BIGINT UNSIGNED NOT NULL, approved_state VARCHAR(20) GENERATED ALWAYS AS('approved') STORED,
 attempt_approved_state VARCHAR(16) GENERATED ALWAYS AS('approved') STORED,
 selected_band_index INT UNSIGNED NOT NULL, snapshot_json JSON NOT NULL, reason LONGTEXT NOT NULL, policy_ref VARCHAR(128) NOT NULL,
 scenario VARCHAR(40) NOT NULL DEFAULT 'REVIEW_MATURITY_LOCAL_001', classification VARCHAR(16) NOT NULL DEFAULT 'TEST_ONLY',
 UNIQUE(organization_id,fixture_id,id),UNIQUE(organization_id,id), UNIQUE(organization_id,fixture_id,approach_id,stage_id,attempt_id),
 UNIQUE(organization_id,fixture_id,approach_id,stage_id,review_id),
 CHECK(classification='TEST_ONLY' AND scenario='REVIEW_MATURITY_LOCAL_001'),
 CHECK(CHAR_LENGTH(fixture_id)=32 AND fixture_id NOT REGEXP '[^0-9a-f]'),
 CHECK(CHAR_LENGTH(TRIM(reason))>0 AND CHAR_LENGTH(TRIM(policy_ref))>0),
 CHECK(selected_band_index>=0),
 CHECK(JSON_TYPE(snapshot_json)='OBJECT' AND JSON_CONTAINS_PATH(snapshot_json,'all','$.scale','$.selected_band_index','$.answers') AND JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.scale'))='ARRAY' AND JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.selected_band_index'))='INTEGER' AND selected_band_index<JSON_LENGTH(JSON_EXTRACT(snapshot_json,'$.scale')) AND JSON_EXTRACT(snapshot_json,'$.selected_band_index')=selected_band_index),
 CONSTRAINT mr_dec_approved_attempt_fk FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id,attempt_id,attempt_approved_state)
  REFERENCES maturity_reassessment_attempts(organization_id,fixture_id,approach_id,stage_id,id,state) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT mr_dec_attempt_pair_fk FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id,attempt_id,submission_id,review_id)
  REFERENCES maturity_reassessment_attempts(organization_id,fixture_id,approach_id,stage_id,id,approved_submission_id,approved_review_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id,review_id,submission_id,reviewer_id,approved_state) REFERENCES review_maturity_reviews(organization_id,fixture_id,approach_id,stage_id,id,submission_id,reviewer_id,state) ON DELETE RESTRICT ON UPDATE RESTRICT,
 FOREIGN KEY(reviewer_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
MYSQL
        : <<<'SQLITE'
CREATE TABLE maturity_reassessment_decisions (
 id INTEGER PRIMARY KEY AUTOINCREMENT, public_id CHAR(26) NOT NULL UNIQUE,
 organization_id BIGINT UNSIGNED NOT NULL, fixture_id CHAR(32) NOT NULL,
 approach_id BIGINT UNSIGNED NOT NULL, stage_id BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL,
 attempt_id BIGINT UNSIGNED NOT NULL, submission_id BIGINT UNSIGNED NOT NULL, review_id BIGINT UNSIGNED NOT NULL,
 reviewer_id BIGINT UNSIGNED NOT NULL, approved_state VARCHAR(20) GENERATED ALWAYS AS('approved') STORED,
 attempt_approved_state VARCHAR(16) GENERATED ALWAYS AS('approved') STORED,
 selected_band_index INT UNSIGNED NOT NULL, snapshot_json JSON NOT NULL, reason LONGTEXT NOT NULL, policy_ref VARCHAR(128) NOT NULL,
 scenario VARCHAR(40) NOT NULL DEFAULT 'REVIEW_MATURITY_LOCAL_001', classification VARCHAR(16) NOT NULL DEFAULT 'TEST_ONLY',
 UNIQUE(organization_id,fixture_id,id),UNIQUE(organization_id,id), UNIQUE(organization_id,fixture_id,approach_id,stage_id,attempt_id),
 UNIQUE(organization_id,fixture_id,approach_id,stage_id,review_id),
 CHECK(classification='TEST_ONLY' AND scenario='REVIEW_MATURITY_LOCAL_001'),
 CHECK(length(fixture_id)=32 AND fixture_id NOT GLOB '*[^0-9a-f]*'),
 CHECK(length(TRIM(reason))>0 AND length(TRIM(policy_ref))>0),
 CHECK(selected_band_index>=0),
 CHECK(json_type(snapshot_json)='object' AND json_type(snapshot_json,'$.scale') IS NOT NULL AND json_type(snapshot_json,'$.selected_band_index') IS NOT NULL AND json_type(snapshot_json,'$.answers') IS NOT NULL AND json_type(snapshot_json,'$.scale')='array' AND json_type(snapshot_json,'$.selected_band_index')='integer' AND selected_band_index<json_array_length(JSON_EXTRACT(snapshot_json,'$.scale')) AND JSON_EXTRACT(snapshot_json,'$.selected_band_index')=selected_band_index),
 CONSTRAINT mr_dec_approved_attempt_fk FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id,attempt_id,attempt_approved_state)
  REFERENCES maturity_reassessment_attempts(organization_id,fixture_id,approach_id,stage_id,id,state) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT mr_dec_attempt_pair_fk FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id,attempt_id,submission_id,review_id)
  REFERENCES maturity_reassessment_attempts(organization_id,fixture_id,approach_id,stage_id,id,approved_submission_id,approved_review_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id,review_id,submission_id,reviewer_id,approved_state) REFERENCES review_maturity_reviews(organization_id,fixture_id,approach_id,stage_id,id,submission_id,reviewer_id,state) ON DELETE RESTRICT ON UPDATE RESTRICT,
 FOREIGN KEY(reviewer_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT
);
SQLITE;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        $this->guardRollback();
        Schema::drop('maturity_reassessment_decisions');
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
