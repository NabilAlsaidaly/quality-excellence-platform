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
            throw new RuntimeException('Documents support verified MySQL and SQLite only.');
        }
        $id = $driver === 'mysql' ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $sql = <<<'SQL'
CREATE TABLE documents (
id {ID}, public_id CHAR(26) NOT NULL UNIQUE,
organization_id BIGINT UNSIGNED NOT NULL, created_by BIGINT UNSIGNED NOT NULL,
title VARCHAR(255) NOT NULL, description LONGTEXT NOT NULL, created_at TIMESTAMP NOT NULL,
CONSTRAINT documents_organization_id_unique UNIQUE (organization_id, id),
CONSTRAINT documents_organization_fk FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
CONSTRAINT documents_creator_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
{INDEX}
)
SQL;
        $create = function () use ($driver, $sql, $id): void {
            DB::statement(str_replace(['{ID}', '{INDEX}'], [$id, $driver === 'mysql'
                ? ', INDEX documents_organization_created_idx (organization_id, created_at, id)' : ''], $sql)
                .($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
            if ($driver === 'sqlite') {
                DB::statement('CREATE INDEX documents_organization_created_idx ON documents (organization_id, created_at, id)');
            }
        };
        $driver === 'sqlite' ? DB::transaction($create) : $create();
    }

    public function down(): void
    {
        if (Schema::hasTable('documents') && DB::table('documents')->exists()) {
            throw new RuntimeException('Document identity was preserved; populated rollback requires reviewed forward recovery.');
        }
        Schema::dropIfExists('documents');
    }
};
