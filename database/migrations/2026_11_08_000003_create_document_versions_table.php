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
            throw new RuntimeException('Document versions support verified MySQL and SQLite only.');
        }
        $id = $driver === 'mysql' ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $hashCheck = $driver === 'mysql'
            ? "REGEXP_LIKE(sha256, '^[0-9a-f]{64}$', 'c')"
            : "length(sha256) = 64 AND sha256 NOT GLOB '*[^0-9a-f]*'";
        $diskCheck = $driver === 'mysql' ? "REGEXP_LIKE(storage_disk, '^local$', 'c')" : "storage_disk = 'local' COLLATE BINARY";
        $sql = <<<'SQL'
CREATE TABLE document_versions (
id {ID}, public_id CHAR(26) NOT NULL UNIQUE,
organization_id BIGINT UNSIGNED NOT NULL, document_id BIGINT UNSIGNED NOT NULL,
version_number INTEGER UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
storage_disk VARCHAR(16) NOT NULL, storage_key VARCHAR(255) NOT NULL UNIQUE,
sha256 CHAR(64) NOT NULL, byte_size BIGINT UNSIGNED NOT NULL,
original_filename VARCHAR(255) NOT NULL, detected_media_type VARCHAR(255) NULL,
created_at TIMESTAMP NOT NULL,
CONSTRAINT document_versions_number_unique UNIQUE (document_id, version_number),
CONSTRAINT document_versions_number_check CHECK (version_number >= 1 AND version_number <= 4294967295),
CONSTRAINT document_versions_disk_check CHECK ({DISK_CHECK}),
CONSTRAINT document_versions_hash_check CHECK ({HASH_CHECK}),
CONSTRAINT document_versions_size_check CHECK (byte_size >= 0),
CONSTRAINT document_versions_organization_fk FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
CONSTRAINT document_versions_document_fk FOREIGN KEY (organization_id, document_id) REFERENCES documents(organization_id, id) ON DELETE RESTRICT ON UPDATE RESTRICT,
CONSTRAINT document_versions_actor_fk FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL;
        DB::statement(str_replace(['{ID}', '{HASH_CHECK}', '{DISK_CHECK}'], [$id, $hashCheck, $diskCheck], $sql)
            .($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    }

    public function down(): void
    {
        foreach (['documents', 'document_versions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Document history was preserved; populated rollback requires reviewed forward recovery.');
            }
        }
        Schema::dropIfExists('document_versions');
    }
};
