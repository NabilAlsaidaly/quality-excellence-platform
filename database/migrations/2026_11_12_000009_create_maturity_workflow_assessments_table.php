<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        if (! $mysql && DB::getDriverName() !== 'sqlite') {
            throw new RuntimeException('Unsupported workflow database.');
        }
        $sql = $mysql ? <<<'SQL'
CREATE TABLE maturity_workflow_assessments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL,
 organization_id BIGINT UNSIGNED NOT NULL,
 fixture_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 approach_id BIGINT UNSIGNED NOT NULL,
 scenario VARCHAR(40) NOT NULL DEFAULT 'REVIEW_MATURITY_LOCAL_001',
 classification VARCHAR(16) NOT NULL DEFAULT 'TEST_ONLY',
 created_at DATETIME NOT NULL,
 stage_id BIGINT UNSIGNED NOT NULL, stage_ordinal TINYINT UNSIGNED NOT NULL,
 submission_id BIGINT UNSIGNED NOT NULL, review_id BIGINT UNSIGNED NOT NULL, reviewer_id BIGINT UNSIGNED NOT NULL,
 previous_assessment_id BIGINT UNSIGNED NULL,
 previous_ordinal TINYINT UNSIGNED GENERATED ALWAYS AS(CASE WHEN stage_ordinal>1 THEN stage_ordinal-1 ELSE NULL END) STORED,
 approved_state VARCHAR(20) GENERATED ALWAYS AS('approved') STORED,
 selected_band_index INT UNSIGNED NOT NULL, snapshot_json JSON NOT NULL,
 reason LONGTEXT NOT NULL, notes LONGTEXT NULL,
 CONSTRAINT mw_assess_public UNIQUE(public_id),
 CONSTRAINT mw_assess_tenant UNIQUE(organization_id,id),
 CONSTRAINT mw_assess_scope UNIQUE(organization_id,fixture_id,approach_id,id),
 CONSTRAINT mw_assess_class CHECK(classification='TEST_ONLY' AND scenario='REVIEW_MATURITY_LOCAL_001'),
 CONSTRAINT mw_assess_fixture CHECK(CHAR_LENGTH(fixture_id)=32 AND fixture_id NOT REGEXP '[^0-9a-f]'),
 CONSTRAINT mw_assess_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT mw_assess_approach FOREIGN KEY(organization_id,approach_id) REFERENCES approaches(organization_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT mw_assess_first_only UNIQUE(organization_id,fixture_id,approach_id,stage_id),
 CONSTRAINT mw_assess_previous_identity UNIQUE(organization_id,fixture_id,approach_id,id,stage_ordinal),
 CONSTRAINT mw_assess_sequence CHECK((stage_ordinal=1 AND previous_assessment_id IS NULL) OR (stage_ordinal BETWEEN 2 AND 5 AND previous_assessment_id IS NOT NULL)),
 CONSTRAINT mw_assess_reason CHECK(CHAR_LENGTH(TRIM(reason))>0),
 CONSTRAINT mw_assess_json CHECK(JSON_TYPE(snapshot_json)='OBJECT' AND JSON_CONTAINS_PATH(snapshot_json,'all','$.scale','$.selected_band_index','$.answers')
  AND JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.scale'))='ARRAY'
  AND JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.selected_band_index'))='INTEGER'
  AND selected_band_index<JSON_LENGTH(JSON_EXTRACT(snapshot_json,'$.scale'))
  AND JSON_EXTRACT(snapshot_json,'$.selected_band_index')=selected_band_index),
 CONSTRAINT mw_assess_stage_fk FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id,stage_ordinal)
  REFERENCES maturity_workflow_stages(organization_id,fixture_id,approach_id,id,stage_ordinal) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT mw_assess_review_fk FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id,review_id,submission_id,reviewer_id,approved_state)
  REFERENCES review_maturity_reviews(organization_id,fixture_id,approach_id,stage_id,id,submission_id,reviewer_id,state) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT mw_assess_previous_fk FOREIGN KEY(organization_id,fixture_id,approach_id,previous_assessment_id,previous_ordinal)
  REFERENCES maturity_workflow_assessments(organization_id,fixture_id,approach_id,id,stage_ordinal) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT mw_assess_reviewer_id_fk FOREIGN KEY(reviewer_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
SQL
        : <<<'SQLITE'
CREATE TABLE maturity_workflow_assessments (
 id INTEGER PRIMARY KEY AUTOINCREMENT, public_id CHAR(26) NOT NULL,
 organization_id BIGINT UNSIGNED NOT NULL,
 fixture_id CHAR(32) NOT NULL,
 approach_id BIGINT UNSIGNED NOT NULL,
 scenario VARCHAR(40) NOT NULL DEFAULT 'REVIEW_MATURITY_LOCAL_001',
 classification VARCHAR(16) NOT NULL DEFAULT 'TEST_ONLY',
 created_at DATETIME NOT NULL,
 stage_id BIGINT UNSIGNED NOT NULL, stage_ordinal TINYINT UNSIGNED NOT NULL,
 submission_id BIGINT UNSIGNED NOT NULL, review_id BIGINT UNSIGNED NOT NULL, reviewer_id BIGINT UNSIGNED NOT NULL,
 previous_assessment_id BIGINT UNSIGNED NULL,
 previous_ordinal TINYINT UNSIGNED GENERATED ALWAYS AS(CASE WHEN stage_ordinal>1 THEN stage_ordinal-1 ELSE NULL END) STORED,
 approved_state VARCHAR(20) GENERATED ALWAYS AS('approved') STORED,
 selected_band_index INT UNSIGNED NOT NULL, snapshot_json JSON NOT NULL,
 reason LONGTEXT NOT NULL, notes LONGTEXT NULL,
 CONSTRAINT mw_assess_public UNIQUE(public_id),
 CONSTRAINT mw_assess_tenant UNIQUE(organization_id,id),
 CONSTRAINT mw_assess_scope UNIQUE(organization_id,fixture_id,approach_id,id),
 CONSTRAINT mw_assess_class CHECK(classification='TEST_ONLY' AND scenario='REVIEW_MATURITY_LOCAL_001'),
 CONSTRAINT mw_assess_fixture CHECK(length(fixture_id)=32 AND fixture_id NOT GLOB '*[^0-9a-f]*'),
 CONSTRAINT mw_assess_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT mw_assess_approach FOREIGN KEY(organization_id,approach_id) REFERENCES approaches(organization_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT mw_assess_first_only UNIQUE(organization_id,fixture_id,approach_id,stage_id),
 CONSTRAINT mw_assess_previous_identity UNIQUE(organization_id,fixture_id,approach_id,id,stage_ordinal),
 CONSTRAINT mw_assess_sequence CHECK((stage_ordinal=1 AND previous_assessment_id IS NULL) OR (stage_ordinal BETWEEN 2 AND 5 AND previous_assessment_id IS NOT NULL)),
 CONSTRAINT mw_assess_reason CHECK(length(TRIM(reason))>0),
 CONSTRAINT mw_assess_json CHECK(json_type(snapshot_json)='object' AND (json_type(snapshot_json,'$.scale') IS NOT NULL AND json_type(snapshot_json,'$.selected_band_index') IS NOT NULL AND json_type(snapshot_json,'$.answers') IS NOT NULL)
  AND json_type(snapshot_json,'$.scale')='array'
  AND json_type(snapshot_json,'$.selected_band_index')='integer'
  AND selected_band_index<json_array_length(JSON_EXTRACT(snapshot_json,'$.scale'))
  AND JSON_EXTRACT(snapshot_json,'$.selected_band_index')=selected_band_index),
 CONSTRAINT mw_assess_stage_fk FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id,stage_ordinal)
  REFERENCES maturity_workflow_stages(organization_id,fixture_id,approach_id,id,stage_ordinal) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT mw_assess_review_fk FOREIGN KEY(organization_id,fixture_id,approach_id,stage_id,review_id,submission_id,reviewer_id,approved_state)
  REFERENCES review_maturity_reviews(organization_id,fixture_id,approach_id,stage_id,id,submission_id,reviewer_id,state) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT mw_assess_previous_fk FOREIGN KEY(organization_id,fixture_id,approach_id,previous_assessment_id,previous_ordinal)
  REFERENCES maturity_workflow_assessments(organization_id,fixture_id,approach_id,id,stage_ordinal) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT mw_assess_reviewer_id_fk FOREIGN KEY(reviewer_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT
,
 CONSTRAINT maturity_workflow_as_uint_0 CHECK(organization_id IS NULL OR organization_id>=0),
 CONSTRAINT maturity_workflow_as_uint_1 CHECK(approach_id IS NULL OR approach_id>=0),
 CONSTRAINT maturity_workflow_as_uint_2 CHECK(stage_id IS NULL OR stage_id>=0),
 CONSTRAINT maturity_workflow_as_uint_3 CHECK(stage_ordinal IS NULL OR stage_ordinal>=0),
 CONSTRAINT maturity_workflow_as_uint_4 CHECK(submission_id IS NULL OR submission_id>=0),
 CONSTRAINT maturity_workflow_as_uint_5 CHECK(review_id IS NULL OR review_id>=0),
 CONSTRAINT maturity_workflow_as_uint_6 CHECK(reviewer_id IS NULL OR reviewer_id>=0),
 CONSTRAINT maturity_workflow_as_uint_7 CHECK(previous_assessment_id IS NULL OR previous_assessment_id>=0),
 CONSTRAINT maturity_workflow_as_uint_8 CHECK(previous_ordinal IS NULL OR previous_ordinal>=0),
 CONSTRAINT maturity_workflow_as_uint_9 CHECK(selected_band_index IS NULL OR selected_band_index>=0)
)
SQLITE;
        DB::statement($sql);
    }

    public function down(): void
    {
        $this->guardRollback();
        Schema::drop('maturity_workflow_assessments');
    }

    private function guardRollback(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported workflow database.');
        }
        // Check every dependency BEFORE the first destructive statement, including roots/grants only.
        $expected = ['maturity_workflow_stages' => ['columns' => ['id', 'public_id', 'organization_id', 'fixture_id', 'approach_id', 'scenario', 'classification', 'created_at', 'stage', 'stage_ordinal'],
            'constraints' => ['mw_stage_public', 'mw_stage_tenant', 'mw_stage_scope', 'mw_stage_class', 'mw_stage_fixture', 'mw_stage_org', 'mw_stage_approach', 'mw_stage_kind', 'mw_stage_order', 'mw_stage_once', 'mw_stage_ordinal']],
            'review_maturity_assignments' => ['columns' => ['id', 'public_id', 'organization_id', 'fixture_id', 'approach_id', 'scenario', 'classification', 'created_at', 'grantee_id', 'issuer_id', 'stage_id', 'operation', 'starts_at', 'expires_at', 'revoked_at', 'revoked_by', 'scope_key'],
                'constraints' => ['rm_grant_public', 'rm_grant_tenant', 'rm_grant_scope', 'rm_grant_class', 'rm_grant_fixture', 'rm_grant_org', 'rm_grant_approach', 'rm_grant_once', 'rm_grant_identity', 'rm_grant_operation', 'rm_grant_period', 'rm_grant_revoked', 'rm_grant_stage_fk', 'rm_grant_grantee_id_fk', 'rm_grant_issuer_id_fk', 'rm_grant_revoked_by_fk']],
            'maturity_workflow_configurations' => ['columns' => ['id', 'public_id', 'organization_id', 'fixture_id', 'approach_id', 'scenario', 'classification', 'created_at', 'stage_id', 'version_number', 'actor_id', 'configuration_json', 'fingerprint'],
                'constraints' => ['mw_config_public', 'mw_config_tenant', 'mw_config_scope', 'mw_config_class', 'mw_config_fixture', 'mw_config_org', 'mw_config_approach', 'mw_config_version', 'mw_config_identity', 'mw_config_number', 'mw_config_hash', 'mw_config_json', 'mw_config_stage_fk', 'mw_config_actor_id_fk']],
            'maturity_workflow_submissions' => ['columns' => ['id', 'public_id', 'organization_id', 'fixture_id', 'approach_id', 'scenario', 'classification', 'created_at', 'stage_id', 'configuration_id', 'version_number', 'actor_id', 'content_json', 'fingerprint'],
                'constraints' => ['mw_sub_public', 'mw_sub_tenant', 'mw_sub_scope', 'mw_sub_class', 'mw_sub_fixture', 'mw_sub_org', 'mw_sub_approach', 'mw_sub_version', 'mw_sub_identity', 'mw_sub_number', 'mw_sub_hash', 'mw_sub_json', 'mw_sub_config_fk', 'mw_sub_actor_id_fk']],
            'maturity_workflow_evidence' => ['columns' => ['id', 'public_id', 'organization_id', 'fixture_id', 'approach_id', 'scenario', 'classification', 'created_at', 'stage_id', 'submission_id', 'question_public_id', 'expected_evidence_public_id', 'document_id', 'document_version_id'],
                'constraints' => ['mw_evidence_public', 'mw_evidence_tenant', 'mw_evidence_scope', 'mw_evidence_class', 'mw_evidence_fixture', 'mw_evidence_org', 'mw_evidence_approach', 'mw_evidence_once', 'mw_evidence_submission_fk', 'mw_evidence_version_fk']],
            'review_maturity_reviews' => ['columns' => ['id', 'public_id', 'organization_id', 'fixture_id', 'approach_id', 'scenario', 'classification', 'created_at', 'stage_id', 'target_kind', 'approach_revision_id', 'submission_id', 'review_number', 'fingerprint', 'opened_by', 'request_key', 'state', 'reviewer_id', 'decision_assignment_id', 'decision_key', 'decision_fingerprint', 'decided_at', 'reason', 'supersedes_id', 'previous_review_number', 'scope_key', 'active_slot', 'decision_operation'],
                'constraints' => ['rm_review_public', 'rm_review_tenant', 'rm_review_scope', 'rm_review_class', 'rm_review_fixture', 'rm_review_org', 'rm_review_approach', 'rm_review_active', 'rm_review_number', 'rm_review_scope_identity', 'rm_review_previous_identity', 'rm_review_chain', 'rm_review_approved_identity', 'rm_review_request', 'rm_review_decision_request', 'rm_review_target', 'rm_review_version', 'rm_review_hash', 'rm_review_decision_hash', 'rm_review_transition', 'rm_review_time', 'rm_review_stage_fk', 'rm_review_revision_fk', 'rm_review_submission_fk', 'rm_review_grant_fk', 'rm_review_supersedes_fk', 'rm_review_opened_by_fk', 'rm_review_reviewer_id_fk']],
            'maturity_workflow_assessments' => ['columns' => ['id', 'public_id', 'organization_id', 'fixture_id', 'approach_id', 'scenario', 'classification', 'created_at', 'stage_id', 'stage_ordinal', 'submission_id', 'review_id', 'reviewer_id', 'previous_assessment_id', 'previous_ordinal', 'approved_state', 'selected_band_index', 'snapshot_json', 'reason', 'notes'],
                'constraints' => ['mw_assess_public', 'mw_assess_tenant', 'mw_assess_scope', 'mw_assess_class', 'mw_assess_fixture', 'mw_assess_org', 'mw_assess_approach', 'mw_assess_first_only', 'mw_assess_previous_identity', 'mw_assess_sequence', 'mw_assess_reason', 'mw_assess_json', 'mw_assess_stage_fk', 'mw_assess_review_fk', 'mw_assess_previous_fk', 'mw_assess_reviewer_id_fk']],
            'review_maturity_audit' => ['columns' => ['id', 'public_id', 'organization_id', 'fixture_id', 'approach_id', 'scenario', 'classification', 'created_at', 'actor_id', 'subject_id', 'stage_id', 'assignment_id', 'review_id', 'operation', 'event', 'event_key', 'scope_key'],
                'constraints' => ['rm_audit_public', 'rm_audit_tenant', 'rm_audit_scope', 'rm_audit_class', 'rm_audit_fixture', 'rm_audit_org', 'rm_audit_approach', 'rm_audit_event', 'rm_audit_operation_scope', 'rm_audit_reference', 'rm_audit_stage_fk', 'rm_audit_grant_fk', 'rm_audit_review_fk', 'rm_audit_actor_id_fk', 'rm_audit_subject_id_fk']],
            'review_maturity_notifications' => ['columns' => ['id', 'public_id', 'organization_id', 'fixture_id', 'approach_id', 'scenario', 'classification', 'created_at', 'recipient_id', 'stage_id', 'review_id', 'event', 'event_key', 'title', 'reason', 'read_at', 'scope_key'],
                'constraints' => ['rm_notice_public', 'rm_notice_tenant', 'rm_notice_scope', 'rm_notice_class', 'rm_notice_fixture', 'rm_notice_org', 'rm_notice_approach', 'rm_notice_once', 'rm_notice_event', 'rm_notice_key', 'rm_notice_text', 'rm_notice_read', 'rm_notice_review_fk', 'rm_notice_recipient_id_fk']]];
        $owned = array_keys($expected);
        $protected = array_merge($owned, ['approach_content_revisions', 'document_versions']);
        if (DB::getDriverName() === 'mysql') {
            $database = DB::connection()->getDatabaseName();
            $external = DB::table('information_schema.KEY_COLUMN_USAGE')->where('REFERENCED_TABLE_SCHEMA', $database)
                ->whereIn('REFERENCED_TABLE_NAME', $protected)
                ->where(fn ($query) => $query->where('TABLE_SCHEMA', '!=', $database)->orWhereNotIn('TABLE_NAME', $owned))->exists();
            if ($external) {
                throw new RuntimeException('Unknown external workflow dependency; forward review required before any DROP/ALTER.');
            }
        } else {
            foreach (Schema::getTables() as $table) {
                if (in_array($table['name'], $owned, true)) {
                    continue;
                }
                foreach (Schema::getForeignKeys($table['name']) as $key) {
                    if (in_array($key['foreign_table'], $protected, true)) {
                        throw new RuntimeException('Unknown external workflow dependency; forward review required before any DROP/ALTER.');
                    }
                }
            }
        }
        foreach ($expected as $table => $shape) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Review/maturity history preserved before any DROP/ALTER; use reviewed forward recovery.');
            }
            $journal = collect(glob(database_path('migrations/2026_11_12_*')))->first(fn ($path) => str_contains(basename($path), '_create_'.$table.'_table.php'));
            if (! $journal || ! DB::table('migrations')->where('migration', basename($journal, '.php'))->exists()
                || Schema::getColumnListing($table) !== $shape['columns']) {
                throw new RuntimeException('Unknown workflow shape/journal: '.$table.' '.json_encode(Schema::getColumnListing($table)).'; forward review required.');
            }
            $ddl = DB::getDriverName() === 'mysql'
                ? DB::selectOne('SHOW CREATE TABLE '.$table)->{'Create Table'}
                : DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name=?", [$table])->sql;
            $ddl = strtolower(str_replace('`', '', $ddl));
            foreach ($shape['constraints'] as $constraint) {
                // MySQL reports UNIQUE constraints as UNIQUE KEY; SQLite retains the explicit name.
                if (! str_contains($ddl, strtolower($constraint))) {
                    throw new RuntimeException('Workflow constraint drift; forward review required.');
                }
            }
        }
    }
};
