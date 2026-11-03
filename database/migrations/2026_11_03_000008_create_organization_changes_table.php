<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Organization history supports verified MySQL and SQLite only.');
        }
        $id = $driver === 'mysql' ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $sql = <<<'SQL'
CREATE TABLE organization_changes (
id {ID}, organization_id BIGINT UNSIGNED NOT NULL, actor_id BIGINT UNSIGNED NOT NULL,
event VARCHAR(20) NOT NULL, previous_name VARCHAR(255) NULL, new_name VARCHAR(255) NOT NULL,
created_at TIMESTAMP NOT NULL,
CONSTRAINT organization_changes_event_check CHECK (event IN ('created', 'renamed')),
CONSTRAINT organization_changes_organization_fk FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
CONSTRAINT organization_changes_actor_fk FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL;
        // One atomic CREATE with inline constraints; never adopt existing tables.
        DB::statement(str_replace('{ID}', $id, $sql).($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_changes');
    }
};
