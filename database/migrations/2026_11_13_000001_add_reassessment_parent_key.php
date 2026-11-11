<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertTarget();
        if (collect(Schema::getIndexes('maturity_workflow_assessments'))->contains('name', 'mw_assess_reassessment_identity')) {
            throw new RuntimeException('Existing unjournaled reassessment key; reviewed recovery required.');
        }
        $sql = DB::getDriverName() === 'mysql' ? <<<'MYSQL'
ALTER TABLE maturity_workflow_assessments ADD CONSTRAINT mw_assess_reassessment_identity UNIQUE(organization_id,fixture_id,approach_id,stage_id,id);
MYSQL
        : <<<'SQLITE'
CREATE UNIQUE INDEX mw_assess_reassessment_identity ON maturity_workflow_assessments(organization_id,fixture_id,approach_id,stage_id,id);
SQLITE;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        $this->assertRollbackSafe();
        $key = collect(Schema::getIndexes('maturity_workflow_assessments'))->firstWhere('name', 'mw_assess_reassessment_identity');
        $original = collect(Schema::getIndexes('maturity_workflow_assessments'))->firstWhere('name', 'mw_assess_first_only');
        if (DB::getDriverName() === 'sqlite') {
            // SQLite names the backing index automatically, retaining the constraint name in CREATE SQL.
            $ddl = DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name='maturity_workflow_assessments'")->sql;
            $original = str_contains($ddl, 'CONSTRAINT mw_assess_first_only')
                ? collect(Schema::getIndexes('maturity_workflow_assessments'))->first(fn ($index) => $index['unique']
                    && $index['columns'] === ['organization_id', 'fixture_id', 'approach_id', 'stage_id']) : null;
        }
        if (! DB::table('migrations')->where('migration', '2026_11_13_000001_add_reassessment_parent_key')->exists()
            || ! $key || ! $key['unique'] || $key['columns'] !== ['organization_id', 'fixture_id', 'approach_id', 'stage_id', 'id']
            || ! $original || ! $original['unique'] || $original['columns'] !== ['organization_id', 'fixture_id', 'approach_id', 'stage_id']) {
            throw new RuntimeException('Unknown reassessment parent key/journal; refuse reverse DDL.');
        }
        DB::statement(DB::getDriverName() === 'mysql'
            ? 'ALTER TABLE maturity_workflow_assessments DROP INDEX mw_assess_reassessment_identity'
            : 'DROP INDEX mw_assess_reassessment_identity');
    }

    public function assertTarget(): void
    {
        $db = DB::connection();
        if (! app()->environment('testing') || $db->getConfig('url')) {
            throw new RuntimeException('TEST_ONLY persistence migrations require an isolated testing database.');
        }
        if ($db->getDriverName() === 'sqlite' && $db->getDatabaseName() === ':memory:') {
            return;
        }
        // The existing CI migration preflight precedes its opted-in PHPUnit step.
        // Its job owns a fresh service database; never require a global CI/config change.
        $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('CI') === 'true'
            && getenv('GITHUB_REPOSITORY') === 'NabilAlsaidaly/quality-excellence-platform'
            && ctype_digit((string) getenv('GITHUB_RUN_ID'));
        if ($db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== 'quality_platform_ci'
            || (getenv('MYSQL_TESTS_ENABLED') !== '1' && ! $ci)) {
            throw new RuntimeException('Refuse persistence DDL outside disposable quality_platform_ci.');
        }
        $actual = $db->selectOne('SELECT DATABASE() AS name, VERSION() AS version, @@server_uuid AS uuid');
        $owned = getenv('MVP_FIXTURE_MYSQL_UUID');
        if ($actual->name !== 'quality_platform_ci' || ! str_starts_with($actual->version, '8.4.')
            || ($owned !== false && $owned !== '' && ! hash_equals($owned, $actual->uuid))) {
            throw new RuntimeException('Disposable database identity mismatch.');
        }
    }

    private function checkExpressions(string $sql): array
    {
        preg_match_all('/\bCHECK\s*\(/i', $sql, $matches, PREG_OFFSET_CAPTURE);
        $result = [];
        foreach ($matches[0] as [$match, $offset]) {
            $start = $offset + strlen($match);
            $depth = 1;
            $quote = false;
            for ($i = $start; $i < strlen($sql) && $depth > 0; $i++) {
                $c = $sql[$i];
                if ($c === "'") {
                    if ($quote && ($sql[$i + 1] ?? '') === "'") {
                        $i++;
                    } else {
                        $quote = ! $quote;
                    }
                } elseif (! $quote && $c === '(') {
                    $depth++;
                } elseif (! $quote && $c === ')') {
                    $depth--;
                }
            }
            if ($depth !== 0) {
                throw new RuntimeException('Unknown CHECK definition; reviewed recovery required.');
            }
            $result[] = substr($sql, $start, $i - $start - 1);
        }

        return $result;
    }

    private function normalizeCheck(string $expression): string
    {
        // Bounded metadata codec: preserve literal bytes AND grouping; never parse arbitrary SQL.
        $expression = str_replace(chr(92).chr(39), chr(39), $expression);
        $parts = preg_split('/(\x27(?:\x27\x27|[^\x27])*\x27)/', $expression, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $part = preg_replace('/(?<![A-Za-z0-9_])_(?:utf8mb4|utf8mb3|ascii|binary)\s*\z/i', '', $part);
                $parts[$i] = preg_replace('/[\s\x60]/', '', strtolower($part));
            }
        }

        return implode('', $parts);
    }

    private function ownedShape(): array
    {
        return json_decode(<<<'EXPECTED'
{"mysql":{"document_intakes":{"columns":["id","public_id","organization_id","fixture_id","document_id","version_id","actor_id","request_key","request_hash","state","scan_result","generation","lease_hash","lease_expires_at","released_at","release_actor_id","release_authority_ref","inspected_media_type","limits_json","created_at","updated_at","scenario","classification"],"unique":[["id"],["public_id"],["organization_id","fixture_id","id"],["organization_id","id"],["organization_id","actor_id","request_key"],["organization_id","document_id","version_id"]],"foreign":[{"columns":["organization_id","document_id","version_id"],"table":"document_versions","foreign_columns":["organization_id","document_id","id"]},{"columns":["actor_id","organization_id"],"table":"users","foreign_columns":["id","organization_id"]},{"columns":["release_actor_id","organization_id"],"table":"users","foreign_columns":["id","organization_id"]}],"checks":["((`classification`='TEST_ONLY')and(`scenario`='DOCUMENT_LOCAL_001'))","((char_length(`fixture_id`)=32)and(not(regexp_like(`fixture_id`,'[^0-9a-f]'))))","((`generation`>0)and(`generation`<=4294967295))","((char_length(`request_hash`)=64)and(not(regexp_like(`request_hash`,'[^0-9a-f]'))))","(`state`in('pending','scanning','clean','suspect','failed','released','release_denied'))","(((`state`in('pending','scanning'))and(`scan_result`isnull))or((`state`in('clean','released','release_denied'))and(`scan_result`isnotnull)and(`scan_result`='clean'))or((`state`='suspect')and(`scan_result`isnotnull)and(`scan_result`='suspect'))or((`state`='failed')and(`scan_result`isnotnull)and(`scan_result`='unavailable')))","(((`state`='scanning')and(`lease_hash`isnotnull)and(`lease_expires_at`isnotnull))or((`state`<>'scanning')and(`lease_hash`isnull)and(`lease_expires_at`isnull)))","(((`state`='released')and(`released_at`isnotnull)and(`release_actor_id`isnotnull)and(`release_actor_id`<>`actor_id`)and(`release_authority_ref`isnotnull)and(char_length(trim(`release_authority_ref`))>0))or((`state`<>'released')and(`released_at`isnull)and(`release_actor_id`isnull)and(`release_authority_ref`isnull)))","(json_type(`limits_json`)='OBJECT')"],"column_metadata":{"id":{"type":"bigint unsigned","nullable":false,"auto_increment":true},"public_id":{"type":"char(26)","nullable":false,"auto_increment":false},"organization_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"fixture_id":{"type":"char(32)","nullable":false,"auto_increment":false},"document_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"version_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"actor_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"request_key":{"type":"char(26)","nullable":false,"auto_increment":false},"request_hash":{"type":"char(64)","nullable":false,"auto_increment":false},"state":{"type":"varchar(16)","nullable":false,"auto_increment":false},"scan_result":{"type":"varchar(16)","nullable":true,"auto_increment":false},"generation":{"type":"int unsigned","nullable":false,"auto_increment":false},"lease_hash":{"type":"char(64)","nullable":true,"auto_increment":false},"lease_expires_at":{"type":"datetime","nullable":true,"auto_increment":false},"released_at":{"type":"datetime","nullable":true,"auto_increment":false},"release_actor_id":{"type":"bigint unsigned","nullable":true,"auto_increment":false},"release_authority_ref":{"type":"varchar(128)","nullable":true,"auto_increment":false},"inspected_media_type":{"type":"varchar(128)","nullable":false,"auto_increment":false},"limits_json":{"type":"json","nullable":false,"auto_increment":false},"created_at":{"type":"datetime","nullable":false,"auto_increment":false},"updated_at":{"type":"datetime","nullable":false,"auto_increment":false},"scenario":{"type":"varchar(40)","nullable":false,"auto_increment":false},"classification":{"type":"varchar(16)","nullable":false,"auto_increment":false}},"generated":{}},"document_intake_events":{"columns":["id","public_id","organization_id","fixture_id","intake_id","actor_id","generation","request_key","event","details_json","created_at","scenario","classification"],"unique":[["id"],["public_id"],["organization_id","fixture_id","id"],["organization_id","id"],["organization_id","intake_id","request_key"]],"foreign":[{"columns":["organization_id","fixture_id","intake_id"],"table":"document_intakes","foreign_columns":["organization_id","fixture_id","id"]},{"columns":["actor_id","organization_id"],"table":"users","foreign_columns":["id","organization_id"]}],"checks":["((`classification`='TEST_ONLY')and(`scenario`='DOCUMENT_LOCAL_001'))","((char_length(`fixture_id`)=32)and(not(regexp_like(`fixture_id`,'[^0-9a-f]'))))","((`generation`>0)and(`generation`<=4294967295))","(eventin('registered','scan_started','scan_clean','scan_suspect','scan_failed','released','release_denied','reconciled','downloaded','access_denied'))","(json_type(`details_json`)='OBJECT')"],"column_metadata":{"id":{"type":"bigint unsigned","nullable":false,"auto_increment":true},"public_id":{"type":"char(26)","nullable":false,"auto_increment":false},"organization_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"fixture_id":{"type":"char(32)","nullable":false,"auto_increment":false},"intake_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"actor_id":{"type":"bigint unsigned","nullable":true,"auto_increment":false},"generation":{"type":"int unsigned","nullable":false,"auto_increment":false},"request_key":{"type":"char(26)","nullable":false,"auto_increment":false},"event":{"type":"varchar(24)","nullable":false,"auto_increment":false},"details_json":{"type":"json","nullable":false,"auto_increment":false},"created_at":{"type":"datetime","nullable":false,"auto_increment":false},"scenario":{"type":"varchar(40)","nullable":false,"auto_increment":false},"classification":{"type":"varchar(16)","nullable":false,"auto_increment":false}},"generated":{}},"maturity_reassessment_attempts":{"columns":["id","public_id","organization_id","fixture_id","approach_id","stage_id","created_at","initial_assessment_id","previous_attempt_id","attempt_number","previous_attempt_number","previous_approved_state","author_id","configuration_id","approved_submission_id","approved_review_id","request_key","request_hash","state","revision","active_slot","scenario","classification"],"unique":[["id"],["public_id"],["organization_id","fixture_id","id"],["organization_id","id"],["organization_id","fixture_id","approach_id","stage_id","id"],["organization_id","fixture_id","approach_id","stage_id","id","state"],["organization_id","fixture_id","approach_id","stage_id","id","approved_submission_id","approved_review_id"],["organization_id","fixture_id","approach_id","stage_id","attempt_number"],["organization_id","fixture_id","approach_id","stage_id","id","attempt_number","state"],["organization_id","fixture_id","approach_id","stage_id","active_slot"],["organization_id","author_id","request_key"]],"foreign":[{"columns":["organization_id","fixture_id","approach_id","stage_id","initial_assessment_id"],"table":"maturity_workflow_assessments","foreign_columns":["organization_id","fixture_id","approach_id","stage_id","id"]},{"columns":["organization_id","fixture_id","approach_id","stage_id","previous_attempt_id","previous_attempt_number","previous_approved_state"],"table":"maturity_reassessment_attempts","foreign_columns":["organization_id","fixture_id","approach_id","stage_id","id","attempt_number","state"]},{"columns":["organization_id","fixture_id","approach_id","stage_id","configuration_id"],"table":"maturity_workflow_configurations","foreign_columns":["organization_id","fixture_id","approach_id","stage_id","id"]},{"columns":["author_id","organization_id"],"table":"users","foreign_columns":["id","organization_id"]}],"checks":["((`classification`='TEST_ONLY')and(`scenario`='REVIEW_MATURITY_LOCAL_001'))","((char_length(`fixture_id`)=32)and(not(regexp_like(`fixture_id`,'[^0-9a-f]'))))","((`revision`>0)and(`revision`<=4294967295))","(`state`in('open','approved'))","(((`state`='open')and(`approved_submission_id`isnull)and(`approved_review_id`isnull))or((`state`='approved')and(`approved_submission_id`isnotnull)and(`approved_review_id`isnotnull)))","((`attempt_number`>0)and(`attempt_number`<=4294967295))","(((`attempt_number`=1)and(`previous_attempt_id`isnull))or((`attempt_number`>1)and(`previous_attempt_id`isnotnull)))","((char_length(`request_hash`)=64)and(not(regexp_like(`request_hash`,'[^0-9a-f]'))))"],"column_metadata":{"id":{"type":"bigint unsigned","nullable":false,"auto_increment":true},"public_id":{"type":"char(26)","nullable":false,"auto_increment":false},"organization_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"fixture_id":{"type":"char(32)","nullable":false,"auto_increment":false},"approach_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"stage_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"created_at":{"type":"datetime","nullable":false,"auto_increment":false},"initial_assessment_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"previous_attempt_id":{"type":"bigint unsigned","nullable":true,"auto_increment":false},"attempt_number":{"type":"int unsigned","nullable":false,"auto_increment":false},"previous_attempt_number":{"type":"int unsigned","nullable":true,"auto_increment":false},"previous_approved_state":{"type":"varchar(16)","nullable":true,"auto_increment":false},"author_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"configuration_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"approved_submission_id":{"type":"bigint unsigned","nullable":true,"auto_increment":false},"approved_review_id":{"type":"bigint unsigned","nullable":true,"auto_increment":false},"request_key":{"type":"char(26)","nullable":false,"auto_increment":false},"request_hash":{"type":"char(64)","nullable":false,"auto_increment":false},"state":{"type":"varchar(16)","nullable":false,"auto_increment":false},"revision":{"type":"int unsigned","nullable":false,"auto_increment":false},"active_slot":{"type":"tinyint unsigned","nullable":true,"auto_increment":false},"scenario":{"type":"varchar(40)","nullable":false,"auto_increment":false},"classification":{"type":"varchar(16)","nullable":false,"auto_increment":false}},"generated":{"previous_attempt_number":{"type":"stored","expression":"(case when (`attempt_number` > 1) then (`attempt_number` - 1) else NULL end)"},"previous_approved_state":{"type":"stored","expression":"_utf8mb4\\'approved\\'"},"active_slot":{"type":"stored","expression":"(case when (`state` = _utf8mb4\\'open\\') then 1 else NULL end)"}}},"maturity_reassessment_decisions":{"columns":["id","public_id","organization_id","fixture_id","approach_id","stage_id","created_at","attempt_id","submission_id","review_id","reviewer_id","approved_state","attempt_approved_state","selected_band_index","snapshot_json","reason","policy_ref","scenario","classification"],"unique":[["id"],["public_id"],["organization_id","fixture_id","id"],["organization_id","id"],["organization_id","fixture_id","approach_id","stage_id","attempt_id"],["organization_id","fixture_id","approach_id","stage_id","review_id"]],"foreign":[{"columns":["organization_id","fixture_id","approach_id","stage_id","attempt_id","attempt_approved_state"],"table":"maturity_reassessment_attempts","foreign_columns":["organization_id","fixture_id","approach_id","stage_id","id","state"]},{"columns":["organization_id","fixture_id","approach_id","stage_id","attempt_id","submission_id","review_id"],"table":"maturity_reassessment_attempts","foreign_columns":["organization_id","fixture_id","approach_id","stage_id","id","approved_submission_id","approved_review_id"]},{"columns":["organization_id","fixture_id","approach_id","stage_id","review_id","submission_id","reviewer_id","approved_state"],"table":"review_maturity_reviews","foreign_columns":["organization_id","fixture_id","approach_id","stage_id","id","submission_id","reviewer_id","state"]},{"columns":["reviewer_id","organization_id"],"table":"users","foreign_columns":["id","organization_id"]}],"checks":["((`classification`='TEST_ONLY')and(`scenario`='REVIEW_MATURITY_LOCAL_001'))","((char_length(`fixture_id`)=32)and(not(regexp_like(`fixture_id`,'[^0-9a-f]'))))","((char_length(trim(`reason`))>0)and(char_length(trim(`policy_ref`))>0))","(`selected_band_index`>=0)","((json_type(`snapshot_json`)='OBJECT')andjson_contains_path(`snapshot_json`,'all','$.scale','$.selected_band_index','$.answers')and(json_type(json_extract(`snapshot_json`,'$.scale'))='ARRAY')and(json_type(json_extract(`snapshot_json`,'$.selected_band_index'))='INTEGER')and(`selected_band_index`<json_length(json_extract(`snapshot_json`,'$.scale')))and(json_extract(`snapshot_json`,'$.selected_band_index')=`selected_band_index`))"],"column_metadata":{"id":{"type":"bigint unsigned","nullable":false,"auto_increment":true},"public_id":{"type":"char(26)","nullable":false,"auto_increment":false},"organization_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"fixture_id":{"type":"char(32)","nullable":false,"auto_increment":false},"approach_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"stage_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"created_at":{"type":"datetime","nullable":false,"auto_increment":false},"attempt_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"submission_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"review_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"reviewer_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"approved_state":{"type":"varchar(20)","nullable":true,"auto_increment":false},"attempt_approved_state":{"type":"varchar(16)","nullable":true,"auto_increment":false},"selected_band_index":{"type":"int unsigned","nullable":false,"auto_increment":false},"snapshot_json":{"type":"json","nullable":false,"auto_increment":false},"reason":{"type":"longtext","nullable":false,"auto_increment":false},"policy_ref":{"type":"varchar(128)","nullable":false,"auto_increment":false},"scenario":{"type":"varchar(40)","nullable":false,"auto_increment":false},"classification":{"type":"varchar(16)","nullable":false,"auto_increment":false}},"generated":{"approved_state":{"type":"stored","expression":"_utf8mb4\\'approved\\'"},"attempt_approved_state":{"type":"stored","expression":"_utf8mb4\\'approved\\'"}}},"maturity_reassessment_events":{"columns":["id","public_id","organization_id","fixture_id","approach_id","stage_id","created_at","attempt_id","actor_id","submission_id","request_key","event","details_json","scenario","classification"],"unique":[["id"],["public_id"],["organization_id","fixture_id","id"],["organization_id","id"],["organization_id","fixture_id","approach_id","stage_id","attempt_id","request_key"]],"foreign":[{"columns":["organization_id","fixture_id","approach_id","stage_id","attempt_id"],"table":"maturity_reassessment_attempts","foreign_columns":["organization_id","fixture_id","approach_id","stage_id","id"]},{"columns":["organization_id","fixture_id","approach_id","stage_id","submission_id"],"table":"maturity_workflow_submissions","foreign_columns":["organization_id","fixture_id","approach_id","stage_id","id"]},{"columns":["actor_id","organization_id"],"table":"users","foreign_columns":["id","organization_id"]}],"checks":["((`classification`='TEST_ONLY')and(`scenario`='REVIEW_MATURITY_LOCAL_001'))","((char_length(`fixture_id`)=32)and(not(regexp_like(`fixture_id`,'[^0-9a-f]'))))","(eventin('opened','saved','changes_requested','approved','access_denied'))","(json_type(`details_json`)='OBJECT')"],"column_metadata":{"id":{"type":"bigint unsigned","nullable":false,"auto_increment":true},"public_id":{"type":"char(26)","nullable":false,"auto_increment":false},"organization_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"fixture_id":{"type":"char(32)","nullable":false,"auto_increment":false},"approach_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"stage_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"created_at":{"type":"datetime","nullable":false,"auto_increment":false},"attempt_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"actor_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"submission_id":{"type":"bigint unsigned","nullable":true,"auto_increment":false},"request_key":{"type":"char(26)","nullable":false,"auto_increment":false},"event":{"type":"varchar(24)","nullable":false,"auto_increment":false},"details_json":{"type":"json","nullable":false,"auto_increment":false},"scenario":{"type":"varchar(40)","nullable":false,"auto_increment":false},"classification":{"type":"varchar(16)","nullable":false,"auto_increment":false}},"generated":{}}},"sqlite":{"document_intakes":{"columns":["id","public_id","organization_id","fixture_id","document_id","version_id","actor_id","request_key","request_hash","state","scan_result","generation","lease_hash","lease_expires_at","released_at","release_actor_id","release_authority_ref","inspected_media_type","limits_json","created_at","updated_at","scenario","classification"],"unique":[["id"],["public_id"],["organization_id","fixture_id","id"],["organization_id","id"],["organization_id","actor_id","request_key"],["organization_id","document_id","version_id"]],"foreign":[{"columns":["organization_id","document_id","version_id"],"table":"document_versions","foreign_columns":["organization_id","document_id","id"]},{"columns":["actor_id","organization_id"],"table":"users","foreign_columns":["id","organization_id"]},{"columns":["release_actor_id","organization_id"],"table":"users","foreign_columns":["id","organization_id"]}],"checks":["classification='TEST_ONLY' AND scenario='DOCUMENT_LOCAL_001'","length(fixture_id)=32 AND fixture_id NOT GLOB '*[^0-9a-f]*'","generation>0 AND generation<=4294967295","length(request_hash)=64 AND request_hash NOT GLOB '*[^0-9a-f]*'","state IN ('pending','scanning','clean','suspect','failed','released','release_denied')","(state IN ('pending','scanning') AND scan_result IS NULL) OR (state IN ('clean','released','release_denied') AND scan_result IS NOT NULL AND scan_result='clean') OR (state='suspect' AND scan_result IS NOT NULL AND scan_result='suspect') OR (state='failed' AND scan_result IS NOT NULL AND scan_result='unavailable')","(state='scanning' AND lease_hash IS NOT NULL AND lease_expires_at IS NOT NULL) OR (state<>'scanning' AND lease_hash IS NULL AND lease_expires_at IS NULL)","(state='released' AND released_at IS NOT NULL AND release_actor_id IS NOT NULL AND release_actor_id<>actor_id AND release_authority_ref IS NOT NULL AND length(TRIM(release_authority_ref))>0) OR (state<>'released' AND released_at IS NULL AND release_actor_id IS NULL AND release_authority_ref IS NULL)","json_type(limits_json)='object'"],"column_metadata":{"id":{"type":"integer","nullable":true,"auto_increment":true},"public_id":{"type":"char(26)","nullable":false,"auto_increment":false},"organization_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"fixture_id":{"type":"char(32)","nullable":false,"auto_increment":false},"document_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"version_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"actor_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"request_key":{"type":"char(26)","nullable":false,"auto_increment":false},"request_hash":{"type":"char(64)","nullable":false,"auto_increment":false},"state":{"type":"varchar(16)","nullable":false,"auto_increment":false},"scan_result":{"type":"varchar(16)","nullable":true,"auto_increment":false},"generation":{"type":"int unsigned","nullable":false,"auto_increment":false},"lease_hash":{"type":"char(64)","nullable":true,"auto_increment":false},"lease_expires_at":{"type":"datetime","nullable":true,"auto_increment":false},"released_at":{"type":"datetime","nullable":true,"auto_increment":false},"release_actor_id":{"type":"bigint unsigned","nullable":true,"auto_increment":false},"release_authority_ref":{"type":"varchar(128)","nullable":true,"auto_increment":false},"inspected_media_type":{"type":"varchar(128)","nullable":false,"auto_increment":false},"limits_json":{"type":"json","nullable":false,"auto_increment":false},"created_at":{"type":"datetime","nullable":false,"auto_increment":false},"updated_at":{"type":"datetime","nullable":false,"auto_increment":false},"scenario":{"type":"varchar(40)","nullable":false,"auto_increment":false},"classification":{"type":"varchar(16)","nullable":false,"auto_increment":false}},"generated":{}},"document_intake_events":{"columns":["id","public_id","organization_id","fixture_id","intake_id","actor_id","generation","request_key","event","details_json","created_at","scenario","classification"],"unique":[["id"],["public_id"],["organization_id","fixture_id","id"],["organization_id","id"],["organization_id","intake_id","request_key"]],"foreign":[{"columns":["organization_id","fixture_id","intake_id"],"table":"document_intakes","foreign_columns":["organization_id","fixture_id","id"]},{"columns":["actor_id","organization_id"],"table":"users","foreign_columns":["id","organization_id"]}],"checks":["classification='TEST_ONLY' AND scenario='DOCUMENT_LOCAL_001'","length(fixture_id)=32 AND fixture_id NOT GLOB '*[^0-9a-f]*'","generation>0 AND generation<=4294967295","event IN ('registered','scan_started','scan_clean','scan_suspect','scan_failed','released','release_denied','reconciled','downloaded','access_denied')","json_type(details_json)='object'"],"column_metadata":{"id":{"type":"integer","nullable":true,"auto_increment":true},"public_id":{"type":"char(26)","nullable":false,"auto_increment":false},"organization_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"fixture_id":{"type":"char(32)","nullable":false,"auto_increment":false},"intake_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"actor_id":{"type":"bigint unsigned","nullable":true,"auto_increment":false},"generation":{"type":"int unsigned","nullable":false,"auto_increment":false},"request_key":{"type":"char(26)","nullable":false,"auto_increment":false},"event":{"type":"varchar(24)","nullable":false,"auto_increment":false},"details_json":{"type":"json","nullable":false,"auto_increment":false},"created_at":{"type":"datetime","nullable":false,"auto_increment":false},"scenario":{"type":"varchar(40)","nullable":false,"auto_increment":false},"classification":{"type":"varchar(16)","nullable":false,"auto_increment":false}},"generated":{}},"maturity_reassessment_attempts":{"columns":["id","public_id","organization_id","fixture_id","approach_id","stage_id","created_at","initial_assessment_id","previous_attempt_id","attempt_number","previous_attempt_number","previous_approved_state","author_id","configuration_id","approved_submission_id","approved_review_id","request_key","request_hash","state","revision","active_slot","scenario","classification"],"unique":[["id"],["public_id"],["organization_id","fixture_id","id"],["organization_id","id"],["organization_id","fixture_id","approach_id","stage_id","id"],["organization_id","fixture_id","approach_id","stage_id","id","state"],["organization_id","fixture_id","approach_id","stage_id","id","approved_submission_id","approved_review_id"],["organization_id","fixture_id","approach_id","stage_id","attempt_number"],["organization_id","fixture_id","approach_id","stage_id","id","attempt_number","state"],["organization_id","fixture_id","approach_id","stage_id","active_slot"],["organization_id","author_id","request_key"]],"foreign":[{"columns":["organization_id","fixture_id","approach_id","stage_id","initial_assessment_id"],"table":"maturity_workflow_assessments","foreign_columns":["organization_id","fixture_id","approach_id","stage_id","id"]},{"columns":["organization_id","fixture_id","approach_id","stage_id","previous_attempt_id","previous_attempt_number","previous_approved_state"],"table":"maturity_reassessment_attempts","foreign_columns":["organization_id","fixture_id","approach_id","stage_id","id","attempt_number","state"]},{"columns":["organization_id","fixture_id","approach_id","stage_id","configuration_id"],"table":"maturity_workflow_configurations","foreign_columns":["organization_id","fixture_id","approach_id","stage_id","id"]},{"columns":["author_id","organization_id"],"table":"users","foreign_columns":["id","organization_id"]}],"checks":["classification='TEST_ONLY' AND scenario='REVIEW_MATURITY_LOCAL_001'","length(fixture_id)=32 AND fixture_id NOT GLOB '*[^0-9a-f]*'","revision>0 AND revision<=4294967295","state IN ('open','approved')","(state='open' AND approved_submission_id IS NULL AND approved_review_id IS NULL) OR (state='approved' AND approved_submission_id IS NOT NULL AND approved_review_id IS NOT NULL)","attempt_number>0 AND attempt_number<=4294967295","(attempt_number=1 AND previous_attempt_id IS NULL) OR (attempt_number>1 AND previous_attempt_id IS NOT NULL)","length(request_hash)=64 AND request_hash NOT GLOB '*[^0-9a-f]*'"],"column_metadata":{"id":{"type":"integer","nullable":true,"auto_increment":true},"public_id":{"type":"char(26)","nullable":false,"auto_increment":false},"organization_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"fixture_id":{"type":"char(32)","nullable":false,"auto_increment":false},"approach_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"stage_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"created_at":{"type":"datetime","nullable":false,"auto_increment":false},"initial_assessment_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"previous_attempt_id":{"type":"bigint unsigned","nullable":true,"auto_increment":false},"attempt_number":{"type":"int unsigned","nullable":false,"auto_increment":false},"previous_attempt_number":{"type":"int unsigned","nullable":true,"auto_increment":false},"previous_approved_state":{"type":"varchar(16)","nullable":true,"auto_increment":false},"author_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"configuration_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"approved_submission_id":{"type":"bigint unsigned","nullable":true,"auto_increment":false},"approved_review_id":{"type":"bigint unsigned","nullable":true,"auto_increment":false},"request_key":{"type":"char(26)","nullable":false,"auto_increment":false},"request_hash":{"type":"char(64)","nullable":false,"auto_increment":false},"state":{"type":"varchar(16)","nullable":false,"auto_increment":false},"revision":{"type":"int unsigned","nullable":false,"auto_increment":false},"active_slot":{"type":"tinyint unsigned","nullable":true,"auto_increment":false},"scenario":{"type":"varchar(40)","nullable":false,"auto_increment":false},"classification":{"type":"varchar(16)","nullable":false,"auto_increment":false}},"generated":{"previous_attempt_number":{"type":"stored","expression":"CASE WHEN attempt_number>1 THEN attempt_number-1 ELSE NULL END"},"previous_approved_state":{"type":"stored","expression":"'approved'"},"active_slot":{"type":"stored","expression":"CASE WHEN state='open' THEN 1 ELSE NULL END"}}},"maturity_reassessment_decisions":{"columns":["id","public_id","organization_id","fixture_id","approach_id","stage_id","created_at","attempt_id","submission_id","review_id","reviewer_id","approved_state","attempt_approved_state","selected_band_index","snapshot_json","reason","policy_ref","scenario","classification"],"unique":[["id"],["public_id"],["organization_id","fixture_id","id"],["organization_id","id"],["organization_id","fixture_id","approach_id","stage_id","attempt_id"],["organization_id","fixture_id","approach_id","stage_id","review_id"]],"foreign":[{"columns":["organization_id","fixture_id","approach_id","stage_id","attempt_id","attempt_approved_state"],"table":"maturity_reassessment_attempts","foreign_columns":["organization_id","fixture_id","approach_id","stage_id","id","state"]},{"columns":["organization_id","fixture_id","approach_id","stage_id","attempt_id","submission_id","review_id"],"table":"maturity_reassessment_attempts","foreign_columns":["organization_id","fixture_id","approach_id","stage_id","id","approved_submission_id","approved_review_id"]},{"columns":["organization_id","fixture_id","approach_id","stage_id","review_id","submission_id","reviewer_id","approved_state"],"table":"review_maturity_reviews","foreign_columns":["organization_id","fixture_id","approach_id","stage_id","id","submission_id","reviewer_id","state"]},{"columns":["reviewer_id","organization_id"],"table":"users","foreign_columns":["id","organization_id"]}],"checks":["classification='TEST_ONLY' AND scenario='REVIEW_MATURITY_LOCAL_001'","length(fixture_id)=32 AND fixture_id NOT GLOB '*[^0-9a-f]*'","length(TRIM(reason))>0 AND length(TRIM(policy_ref))>0","selected_band_index>=0","json_type(snapshot_json)='object' AND json_type(snapshot_json,'$.scale') IS NOT NULL AND json_type(snapshot_json,'$.selected_band_index') IS NOT NULL AND json_type(snapshot_json,'$.answers') IS NOT NULL AND json_type(snapshot_json,'$.scale')='array' AND json_type(snapshot_json,'$.selected_band_index')='integer' AND selected_band_index<json_array_length(JSON_EXTRACT(snapshot_json,'$.scale')) AND JSON_EXTRACT(snapshot_json,'$.selected_band_index')=selected_band_index"],"column_metadata":{"id":{"type":"integer","nullable":true,"auto_increment":true},"public_id":{"type":"char(26)","nullable":false,"auto_increment":false},"organization_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"fixture_id":{"type":"char(32)","nullable":false,"auto_increment":false},"approach_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"stage_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"created_at":{"type":"datetime","nullable":false,"auto_increment":false},"attempt_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"submission_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"review_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"reviewer_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"approved_state":{"type":"varchar(20)","nullable":true,"auto_increment":false},"attempt_approved_state":{"type":"varchar(16)","nullable":true,"auto_increment":false},"selected_band_index":{"type":"int unsigned","nullable":false,"auto_increment":false},"snapshot_json":{"type":"json","nullable":false,"auto_increment":false},"reason":{"type":"longtext","nullable":false,"auto_increment":false},"policy_ref":{"type":"varchar(128)","nullable":false,"auto_increment":false},"scenario":{"type":"varchar(40)","nullable":false,"auto_increment":false},"classification":{"type":"varchar(16)","nullable":false,"auto_increment":false}},"generated":{"approved_state":{"type":"stored","expression":"'approved'"},"attempt_approved_state":{"type":"stored","expression":"'approved'"}}},"maturity_reassessment_events":{"columns":["id","public_id","organization_id","fixture_id","approach_id","stage_id","created_at","attempt_id","actor_id","submission_id","request_key","event","details_json","scenario","classification"],"unique":[["id"],["public_id"],["organization_id","fixture_id","id"],["organization_id","id"],["organization_id","fixture_id","approach_id","stage_id","attempt_id","request_key"]],"foreign":[{"columns":["organization_id","fixture_id","approach_id","stage_id","attempt_id"],"table":"maturity_reassessment_attempts","foreign_columns":["organization_id","fixture_id","approach_id","stage_id","id"]},{"columns":["organization_id","fixture_id","approach_id","stage_id","submission_id"],"table":"maturity_workflow_submissions","foreign_columns":["organization_id","fixture_id","approach_id","stage_id","id"]},{"columns":["actor_id","organization_id"],"table":"users","foreign_columns":["id","organization_id"]}],"checks":["classification='TEST_ONLY' AND scenario='REVIEW_MATURITY_LOCAL_001'","length(fixture_id)=32 AND fixture_id NOT GLOB '*[^0-9a-f]*'","event IN ('opened','saved','changes_requested','approved','access_denied')","json_type(details_json)='object'"],"column_metadata":{"id":{"type":"integer","nullable":true,"auto_increment":true},"public_id":{"type":"char(26)","nullable":false,"auto_increment":false},"organization_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"fixture_id":{"type":"char(32)","nullable":false,"auto_increment":false},"approach_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"stage_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"created_at":{"type":"datetime","nullable":false,"auto_increment":false},"attempt_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"actor_id":{"type":"bigint unsigned","nullable":false,"auto_increment":false},"submission_id":{"type":"bigint unsigned","nullable":true,"auto_increment":false},"request_key":{"type":"char(26)","nullable":false,"auto_increment":false},"event":{"type":"varchar(24)","nullable":false,"auto_increment":false},"details_json":{"type":"json","nullable":false,"auto_increment":false},"scenario":{"type":"varchar(40)","nullable":false,"auto_increment":false},"classification":{"type":"varchar(16)","nullable":false,"auto_increment":false}},"generated":{}}}}
EXPECTED, true, flags: JSON_THROW_ON_ERROR)[DB::getDriverName()];
    }

    public function assertParents(string $table): void
    {
        $this->assertTarget();
        $definition = $this->ownedShape()[$table] ?? throw new RuntimeException('Unknown persistence parent contract.');
        foreach ($definition['foreign'] as $foreign) {
            if ($foreign['table'] === $table) {
                // The same atomic CREATE defines this self-reference and its exact candidate key.
                continue;
            }
            if (! Schema::hasTable($foreign['table'])
                || ! collect(Schema::getIndexes($foreign['table']))->contains(fn ($index) => $index['unique'] && $index['columns'] === $foreign['foreign_columns'])) {
                throw new RuntimeException('Required persistence parent/candidate is unavailable: '.$foreign['table']);
            }
        }
    }

    public function assertRollbackSafe(): void
    {
        $this->assertTarget();
        $shape = $this->ownedShape();
        $owned = array_keys($shape);
        foreach (array_merge($owned, ['document_versions', 'maturity_workflow_assessments',
            'maturity_workflow_submissions', 'review_maturity_reviews']) as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Populated document/reassessment history preserved before reverse DDL.');
            }
        }
        foreach (Schema::getTables() as $table) {
            if (in_array($table['name'], $owned, true)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table['name']) as $key) {
                if (in_array($key['foreign_table'], $owned, true)) {
                    throw new RuntimeException('External persistence dependency; reviewed forward recovery required.');
                }
            }
        }
        foreach ($shape as $table => $expected) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $migration = match ($table) {
                'document_intakes' => '2026_11_13_000002_create_document_intakes_table',
                'document_intake_events' => '2026_11_13_000003_create_document_intake_events_table',
                'maturity_reassessment_attempts' => '2026_11_13_000004_create_maturity_reassessment_attempts_table',
                'maturity_reassessment_decisions' => '2026_11_13_000005_create_maturity_reassessment_decisions_table',
                'maturity_reassessment_events' => '2026_11_13_000006_create_maturity_reassessment_events_table',
            };
            if (! DB::table('migrations')->where('migration', $migration)->exists()
                || Schema::getColumnListing($table) !== $expected['columns']) {
                throw new RuntimeException('Unknown persistence shape/journal; do not adopt or drop.');
            }
            foreach (Schema::getColumns($table) as $column) {
                $definition = ['type' => $column['type'], 'nullable' => $column['nullable'], 'auto_increment' => (bool) $column['auto_increment']];
                if ($definition !== $expected['column_metadata'][$column['name']]) {
                    throw new RuntimeException('Persistence column type/nullability/identity drift.');
                }
                $generation = $column['generation'];
                $requiredGeneration = $expected['generated'][$column['name']] ?? null;
                if ($generation !== null && $generation['expression'] === null && DB::getDriverName() === 'sqlite') {
                    // Laravel's SQLite metadata regex requires "AS ("; canonical v4 uses valid "AS(".
                    $ddl = DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name=?", [$table])->sql;
                    if (preg_match('/\b'.preg_quote($column['name'], '/').'\b[^,]*?\bGENERATED\s+ALWAYS\s+AS\s*\(/i', $ddl, $match, PREG_OFFSET_CAPTURE) !== 1) {
                        throw new RuntimeException('Cannot verify the stored SQLite expression.');
                    }
                    $start = $match[0][1] + strlen($match[0][0]);
                    $generation['expression'] = $this->checkExpressions('CHECK('.substr($ddl, $start))[0];
                }
                if (($generation === null) !== ($requiredGeneration === null)
                    || ($generation !== null && (strtolower($generation['type']) !== $requiredGeneration['type']
                        || $this->normalizeCheck($generation['expression'] ?? '') !== $this->normalizeCheck($requiredGeneration['expression'])))) {
                    throw new RuntimeException('Persistence stored-generated expression drift: '.$table.'.'.$column['name'].' '.json_encode([$generation, $requiredGeneration]));
                }
            }
            $keys = Schema::getForeignKeys($table);
            if (count($keys) !== count($expected['foreign'])) {
                throw new RuntimeException('Persistence foreign-key drift.');
            }
            foreach ($expected['foreign'] as $fk) {
                if (! collect($keys)->contains(fn ($key) => $key['columns'] === $fk['columns']
                    && $key['foreign_table'] === $fk['table'] && $key['foreign_columns'] === $fk['foreign_columns']
                    && strtolower($key['on_delete']) === 'restrict' && strtolower($key['on_update']) === 'restrict')) {
                    throw new RuntimeException('Persistence tenant foreign-key drift.');
                }
            }
            $unique = array_map(fn ($index) => $index['columns'], array_values(array_filter(Schema::getIndexes($table), fn ($index) => $index['unique'])));
            if (count($unique) !== count($expected['unique'])) {
                throw new RuntimeException('Persistence unique-key drift.');
            }
            foreach ($expected['unique'] as $columns) {
                if (! in_array($columns, $unique, true)) {
                    throw new RuntimeException('Persistence unique-key columns drift.');
                }
            }
            if (DB::getDriverName() === 'mysql') {
                $checks = DB::select('SELECT cc.CHECK_CLAUSE AS clause, tc.ENFORCED AS enforced FROM information_schema.CHECK_CONSTRAINTS cc JOIN information_schema.TABLE_CONSTRAINTS tc ON tc.CONSTRAINT_SCHEMA=cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME=cc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA=DATABASE() AND tc.TABLE_NAME=?', [$table]);
                if (collect($checks)->contains(fn ($check) => $check->enforced !== 'YES')) {
                    throw new RuntimeException('Persistence CHECK enforcement drift.');
                }
                $actualChecks = array_column($checks, 'clause');
            } else {
                $ddl = DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name=?", [$table])->sql;
                $actualChecks = $this->checkExpressions($ddl);
            }
            $actualChecks = array_map($this->normalizeCheck(...), $actualChecks);
            $expectedChecks = array_map($this->normalizeCheck(...), $expected['checks']);
            sort($actualChecks);
            sort($expectedChecks);
            if ($actualChecks !== $expectedChecks) {
                throw new RuntimeException('Persistence CHECK drift; reviewed forward recovery required.');
            }
        }
    }
};
