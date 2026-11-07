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
            throw new RuntimeException('Approach revisions support verified MySQL and SQLite only.');
        }
        $id = $driver === 'mysql' ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $sql = <<<'SQL'
CREATE TABLE approach_content_revisions (
id {ID}, public_id CHAR(26) NOT NULL UNIQUE,
organization_id BIGINT UNSIGNED NOT NULL, approach_id BIGINT UNSIGNED NOT NULL,
version_number INTEGER UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
body LONGTEXT NOT NULL, created_at TIMESTAMP NOT NULL,
CONSTRAINT approach_revisions_version_unique UNIQUE (approach_id, version_number),
CONSTRAINT approach_revisions_version_check CHECK (version_number >= 1 AND version_number <= 4294967295),
CONSTRAINT approach_revisions_organization_fk FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
CONSTRAINT approach_revisions_approach_fk FOREIGN KEY (organization_id, approach_id) REFERENCES approaches(organization_id, id) ON DELETE RESTRICT ON UPDATE RESTRICT,
CONSTRAINT approach_revisions_actor_fk FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL;
        DB::statement(str_replace('{ID}', $id, $sql).($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    }

    public function down(): void
    {
        // Refuse BEFORE dropping any table when even core identity would be lost.
        if ((Schema::hasTable('approaches') && DB::table('approaches')->exists())
            || (Schema::hasTable('approach_content_revisions') && DB::table('approach_content_revisions')->exists())) {
            throw new RuntimeException('Approach history was preserved; populated rollback requires a reviewed forward recovery plan.');
        }
        Schema::dropIfExists('approach_content_revisions');
    }
};
