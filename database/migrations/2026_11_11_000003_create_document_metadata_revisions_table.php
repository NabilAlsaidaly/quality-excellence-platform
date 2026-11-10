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
            throw new RuntimeException('Document metadata history supports verified MySQL/SQLite only.');
        }
        $id = $driver === 'mysql' ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $sql = <<<'SQL'
CREATE TABLE document_metadata_revisions (
 id {ID}, public_id CHAR(26) NOT NULL,
 organization_id BIGINT UNSIGNED NOT NULL, document_id BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
 version_number INT UNSIGNED NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT NOT NULL, created_at DATETIME NOT NULL,
 CONSTRAINT doc_meta_public UNIQUE (public_id),
 CONSTRAINT doc_meta_tenant UNIQUE (organization_id,id),
 CONSTRAINT doc_meta_version UNIQUE (organization_id,document_id,version_number),
 CONSTRAINT doc_meta_number CHECK (version_number>0 AND version_number<=4294967295),
 CONSTRAINT doc_meta_title CHECK (CHAR_LENGTH(TRIM(title))>0),
 CONSTRAINT doc_meta_description CHECK (CHAR_LENGTH(TRIM(description))>0),
 CONSTRAINT doc_meta_org_fk FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT doc_meta_document_fk FOREIGN KEY (organization_id,document_id) REFERENCES documents(organization_id,id) ON DELETE RESTRICT ON UPDATE RESTRICT,
 CONSTRAINT doc_meta_actor_fk FOREIGN KEY (actor_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL;
        // SQLite's equivalent built-in is length(), not CHAR_LENGTH().
        $statement = str_replace('{ID}', $id, $sql);
        $statement = $driver === 'sqlite' ? str_replace('CHAR_LENGTH(', 'length(', $statement) : $statement;
        if ($driver === 'mysql') {
            DB::statement($statement.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
        } else {
            DB::transaction(fn () => DB::statement($statement));
        }
    }

    public function down(): void
    {
        foreach (['document_workspace_assignments', 'document_workspace_assignment_changes', 'document_metadata_revisions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Document workspace history preserved before any DROP; use reviewed forward recovery.');
            }
        }
        Schema::dropIfExists('document_metadata_revisions');
    }
};
