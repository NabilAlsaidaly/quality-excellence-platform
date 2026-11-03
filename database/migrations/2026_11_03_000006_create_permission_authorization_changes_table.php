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
CREATE TABLE authorization_changes (
id {ID}, organization_id BIGINT UNSIGNED NOT NULL,
actor_id BIGINT UNSIGNED NOT NULL, event VARCHAR(40) NOT NULL,
role_id BIGINT UNSIGNED NULL, subject_user_id BIGINT UNSIGNED NULL, created_at TIMESTAMP NOT NULL,
CONSTRAINT authorization_changes_organization_fk FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
CONSTRAINT authorization_changes_actor_fk FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
CONSTRAINT authorization_changes_subject_fk FOREIGN KEY (subject_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL;
        // One atomic CREATE with inline keys/FKs. No later ALTER/cache operation
        // can leave an unjournaled, unconstrained MySQL table after a SQL failure.
        DB::statement(str_replace('{ID}', $id, $sql).($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    }

    public function down(): void
    {
        Schema::dropIfExists('authorization_changes');
    }
};
