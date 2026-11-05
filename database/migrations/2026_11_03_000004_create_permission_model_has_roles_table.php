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
CREATE TABLE model_has_roles (
role_id BIGINT UNSIGNED NOT NULL, model_type VARCHAR(100) NOT NULL,
model_id BIGINT UNSIGNED NOT NULL, organization_id BIGINT UNSIGNED NOT NULL,
PRIMARY KEY (organization_id, role_id, model_id, model_type),
CONSTRAINT assigned_roles_scope_fk FOREIGN KEY (organization_id, role_id) REFERENCES roles(organization_id, id) ON DELETE RESTRICT ON UPDATE RESTRICT,
CONSTRAINT assigned_roles_membership_fk FOREIGN KEY (model_id, organization_id) REFERENCES organization_memberships(user_id, organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL;
        // One atomic CREATE with inline keys/FKs. No later ALTER/cache operation
        // can leave an unjournaled, unconstrained MySQL table after a SQL failure.
        DB::statement(str_replace('{ID}', $id, $sql).($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_roles');
    }
};
