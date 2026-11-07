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
            throw new RuntimeException('Approach audit supports verified MySQL and SQLite only.');
        }
        $id = $driver === 'mysql' ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $jsonCheck = $driver === 'mysql'
            ? "JSON_TYPE(before_state) = 'OBJECT' AND JSON_TYPE(after_state) = 'OBJECT'"
            : "json_valid(before_state) AND json_valid(after_state) AND json_type(before_state) = 'object' AND json_type(after_state) = 'object'";
        $sql = <<<'SQL'
CREATE TABLE approach_changes (
id {ID}, public_id CHAR(26) NOT NULL UNIQUE,
organization_id BIGINT UNSIGNED NOT NULL, approach_id BIGINT UNSIGNED NOT NULL,
actor_id BIGINT UNSIGNED NOT NULL, event VARCHAR(20) NOT NULL,
before_state JSON NOT NULL, after_state JSON NOT NULL,
previous_owner_id BIGINT UNSIGNED NULL, new_owner_id BIGINT UNSIGNED NULL,
created_at TIMESTAMP NOT NULL,
CONSTRAINT approach_changes_event_check CHECK (event IN ('metadata_updated', 'owner_changed')),
CONSTRAINT approach_changes_state_check CHECK ({JSON_CHECK}),
CONSTRAINT approach_changes_owner_check CHECK (
    (event = 'metadata_updated' AND previous_owner_id IS NULL AND new_owner_id IS NULL) OR
    (event = 'owner_changed' AND previous_owner_id IS NOT NULL AND new_owner_id IS NOT NULL AND previous_owner_id <> new_owner_id)),
CONSTRAINT approach_changes_organization_fk FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
CONSTRAINT approach_changes_approach_fk FOREIGN KEY (organization_id, approach_id) REFERENCES approaches(organization_id, id) ON DELETE RESTRICT ON UPDATE RESTRICT,
CONSTRAINT approach_changes_actor_fk FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
CONSTRAINT approach_changes_previous_owner_fk FOREIGN KEY (organization_id, previous_owner_id) REFERENCES organizational_units(organization_id, id) ON DELETE RESTRICT ON UPDATE RESTRICT,
CONSTRAINT approach_changes_new_owner_fk FOREIGN KEY (organization_id, new_owner_id) REFERENCES organizational_units(organization_id, id) ON DELETE RESTRICT ON UPDATE RESTRICT
{INDEX}
)
SQL;
        $create = function () use ($driver, $id, $jsonCheck, $sql): void {
            DB::statement(str_replace(['{ID}', '{JSON_CHECK}', '{INDEX}'], [$id, $jsonCheck,
                $driver === 'mysql' ? ', INDEX approach_changes_history_idx (organization_id, approach_id, id)' : ''], $sql)
                .($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
            if ($driver === 'sqlite') {
                DB::statement('CREATE INDEX approach_changes_history_idx ON approach_changes (organization_id, approach_id, id)');
            }
        };
        $driver === 'sqlite' ? DB::transaction($create) : $create();
    }

    public function down(): void
    {
        // Refuse before the FIRST DROP, including an identity-only populated bank.
        foreach (['approaches', 'approach_content_revisions', 'approach_changes'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Approach history was preserved; populated rollback requires a reviewed forward recovery plan.');
            }
        }
        Schema::dropIfExists('approach_changes');
    }
};
