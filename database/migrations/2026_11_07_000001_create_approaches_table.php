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
            throw new RuntimeException('Approaches support verified MySQL and SQLite only.');
        }
        $id = $driver === 'mysql' ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $sql = <<<'SQL'
CREATE TABLE approaches (
id {ID}, public_id CHAR(26) NOT NULL UNIQUE,
organization_id BIGINT UNSIGNED NOT NULL, owning_unit_id BIGINT UNSIGNED NOT NULL,
created_by BIGINT UNSIGNED NOT NULL,
name VARCHAR(255) NOT NULL, description LONGTEXT NOT NULL, purpose LONGTEXT NOT NULL, scope LONGTEXT NOT NULL,
objectives LONGTEXT NULL, review_frequency LONGTEXT NULL,
created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL,
CONSTRAINT approaches_organization_id_unique UNIQUE (organization_id, id),
CONSTRAINT approaches_organization_fk FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
CONSTRAINT approaches_owner_fk FOREIGN KEY (organization_id, owning_unit_id) REFERENCES organizational_units(organization_id, id) ON DELETE RESTRICT ON UPDATE RESTRICT,
CONSTRAINT approaches_creator_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
{ORDER_INDEXES}
)
SQL;
        // MySQL: constraints AND query indexes in one atomic DDL statement.
        $indexes = $driver === 'mysql' ? ', INDEX approaches_organization_created_idx (organization_id, created_at, id), INDEX approaches_organization_name_idx (organization_id, name, id)' : '';
        $statement = str_replace(['{ID}', '{ORDER_INDEXES}'], [$id, $indexes], $sql);
        if ($driver === 'mysql') {
            DB::statement($statement.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        } else {
            // SQLite supports transactional DDL, unlike MySQL's implicit commit.
            DB::transaction(function () use ($statement): void {
                DB::statement($statement);
                DB::statement('CREATE INDEX approaches_organization_created_idx ON approaches (organization_id, created_at, id)');
                DB::statement('CREATE INDEX approaches_organization_name_idx ON approaches (organization_id, name, id)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('approaches') && DB::table('approaches')->exists()) {
            throw new RuntimeException('Approach records were preserved; populated rollback requires a reviewed forward recovery plan.');
        }
        Schema::dropIfExists('approaches');
    }
};
