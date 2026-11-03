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
            throw new RuntimeException('Permission schema supports verified MySQL and SQLite only.');
        }
        $id = $driver === 'mysql' ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $sql = <<<'SQL'
CREATE TABLE roles (
id {ID}, organization_id BIGINT UNSIGNED NOT NULL,
name VARCHAR(125) NOT NULL, guard_name VARCHAR(30) NOT NULL,
created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
CONSTRAINT roles_organization_id_unique UNIQUE (organization_id, id),
CONSTRAINT roles_organization_name_guard_unique UNIQUE (organization_id, name, guard_name),
CONSTRAINT roles_organization_fk FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL;
        // One atomic CREATE with inline keys/FKs. No later ALTER/cache operation
        // can leave an unjournaled, unconstrained MySQL table after a SQL failure.
        DB::statement(str_replace('{ID}', $id, $sql).($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
