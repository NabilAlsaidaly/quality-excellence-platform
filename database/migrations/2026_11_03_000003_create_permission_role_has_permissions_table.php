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
CREATE TABLE role_has_permissions (
permission_id BIGINT UNSIGNED NOT NULL, role_id BIGINT UNSIGNED NOT NULL,
PRIMARY KEY (permission_id, role_id),
CONSTRAINT role_permissions_permission_fk FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
CONSTRAINT role_permissions_role_fk FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL;
        // One atomic CREATE with inline keys/FKs. No later ALTER/cache operation
        // can leave an unjournaled, unconstrained MySQL table after a SQL failure.
        DB::statement(str_replace('{ID}', $id, $sql).($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
    }
};
