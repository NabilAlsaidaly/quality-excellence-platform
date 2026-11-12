<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->guardTarget();
        if (Schema::hasTable('document_intake_events')) {
            throw new RuntimeException('Existing persistence table; reviewed recovery required before retry.');
        }
        if (DB::getDriverName() === 'sqlite') {
            (require __DIR__.'/2026_11_13_000001_add_reassessment_parent_key.php')->assertParents('document_intake_events');
        }
        $sql = DB::getDriverName() === 'mysql' ? <<<'MYSQL'
CREATE TABLE document_intake_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE,
 organization_id BIGINT UNSIGNED NOT NULL, fixture_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, intake_id BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NULL,
 generation INT UNSIGNED NOT NULL, request_key CHAR(26) NOT NULL,
 event VARCHAR(24) NOT NULL, details_json JSON NOT NULL, created_at DATETIME NOT NULL,
 scenario VARCHAR(40) NOT NULL DEFAULT 'DOCUMENT_LOCAL_001', classification VARCHAR(16) NOT NULL DEFAULT 'TEST_ONLY',
 UNIQUE(organization_id,fixture_id,id),UNIQUE(organization_id,id), UNIQUE(organization_id,intake_id,request_key),
 CHECK(classification='TEST_ONLY' AND scenario='DOCUMENT_LOCAL_001'),
 CHECK(CHAR_LENGTH(fixture_id)=32 AND fixture_id NOT REGEXP '[^0-9a-f]'),
 CHECK(generation>0 AND generation<=4294967295),
 CHECK(event IN ('registered','scan_started','scan_clean','scan_suspect','scan_failed','released','release_denied','reconciled','downloaded','access_denied')),
 CHECK(JSON_TYPE(details_json)='OBJECT'),
 FOREIGN KEY(organization_id,fixture_id,intake_id) REFERENCES document_intakes(organization_id,fixture_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 FOREIGN KEY(actor_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 INDEX die_history(organization_id,intake_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
MYSQL
        : <<<'SQLITE'
CREATE TABLE document_intake_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT, public_id CHAR(26) NOT NULL UNIQUE,
 organization_id BIGINT UNSIGNED NOT NULL, fixture_id CHAR(32) NOT NULL, intake_id BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NULL,
 generation INT UNSIGNED NOT NULL, request_key CHAR(26) NOT NULL,
 event VARCHAR(24) NOT NULL, details_json JSON NOT NULL, created_at DATETIME NOT NULL,
 scenario VARCHAR(40) NOT NULL DEFAULT 'DOCUMENT_LOCAL_001', classification VARCHAR(16) NOT NULL DEFAULT 'TEST_ONLY',
 UNIQUE(organization_id,fixture_id,id),UNIQUE(organization_id,id), UNIQUE(organization_id,intake_id,request_key),
 CHECK(classification='TEST_ONLY' AND scenario='DOCUMENT_LOCAL_001'),
 CHECK(length(fixture_id)=32 AND fixture_id NOT GLOB '*[^0-9a-f]*'),
 CHECK(generation>0 AND generation<=4294967295),
 CHECK(event IN ('registered','scan_started','scan_clean','scan_suspect','scan_failed','released','release_denied','reconciled','downloaded','access_denied')),
 CHECK(json_type(details_json)='object'),
 FOREIGN KEY(organization_id,fixture_id,intake_id) REFERENCES document_intakes(organization_id,fixture_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 FOREIGN KEY(actor_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT
);
CREATE INDEX die_history ON document_intake_events(organization_id,intake_id,id);
SQLITE;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        $this->guardRollback();
        Schema::drop('document_intake_events');
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
