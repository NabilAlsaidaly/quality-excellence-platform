<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'principal_type')) {
            throw new RuntimeException('Identity expansion already exists without this journal entry; operator verification required.');
        }
        if (DB::getDriverName() === 'mysql') {
            // One atomic ALTER: never remove old uniqueness before its replacement.
            DB::statement(<<<'SQL'
ALTER TABLE users
ADD COLUMN organization_id BIGINT UNSIGNED NULL,
ADD COLUMN public_id CHAR(26) NULL,
ADD COLUMN principal_type VARCHAR(16) NOT NULL DEFAULT 'legacy',
ADD COLUMN identity_namespace VARCHAR(48) GENERATED ALWAYS AS
 (CASE WHEN principal_type = 'institution' THEN CONCAT('institution:', organization_id) ELSE principal_type END) VIRTUAL,
ADD CONSTRAINT users_identity_organization_fk FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
ADD CONSTRAINT users_identity_shape_check CHECK (
 (principal_type = 'legacy' AND organization_id IS NULL AND public_id IS NULL) OR
 (principal_type = 'platform' AND organization_id IS NULL AND public_id IS NOT NULL) OR
 (principal_type = 'institution' AND organization_id IS NOT NULL AND public_id IS NOT NULL)),
ADD CONSTRAINT users_identity_email_check CHECK (principal_type = 'legacy' OR
 (OCTET_LENGTH(email) = CHAR_LENGTH(email) AND CAST(email AS BINARY) = CAST(LOWER(email) AS BINARY))),
ADD UNIQUE KEY users_public_id_unique (public_id),
ADD UNIQUE KEY users_id_organization_unique (id, organization_id),
ADD UNIQUE KEY users_namespace_email_unique (identity_namespace, email),
DROP INDEX users_email_unique
SQL);

            return;
        }
        if (DB::getDriverName() !== 'sqlite') {
            throw new RuntimeException('Identity schema requires MySQL or SQLite.');
        }
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE users ADD COLUMN organization_id INTEGER NULL REFERENCES organizations(id) ON DELETE RESTRICT ON UPDATE RESTRICT');
            DB::statement('ALTER TABLE users ADD COLUMN public_id CHAR(26) NULL');
            DB::statement(<<<'SQL'
ALTER TABLE users ADD COLUMN principal_type VARCHAR(16) NOT NULL DEFAULT 'legacy'
CONSTRAINT users_identity_shape_check CHECK (
 (principal_type = 'legacy' AND organization_id IS NULL AND public_id IS NULL) OR
 (principal_type = 'platform' AND organization_id IS NULL AND public_id IS NOT NULL) OR
 (principal_type = 'institution' AND organization_id IS NOT NULL AND public_id IS NOT NULL))
CONSTRAINT users_identity_email_check CHECK (principal_type = 'legacy' OR
 (length(CAST(email AS BLOB)) = length(email) AND email = lower(email) COLLATE BINARY))
SQL);
            DB::statement("ALTER TABLE users ADD COLUMN identity_namespace VARCHAR(48) GENERATED ALWAYS AS (CASE WHEN principal_type = 'institution' THEN 'institution:' || organization_id ELSE principal_type END) VIRTUAL");
            DB::statement('CREATE UNIQUE INDEX users_public_id_unique ON users(public_id)');
            DB::statement('CREATE UNIQUE INDEX users_id_organization_unique ON users(id, organization_id)');
            DB::statement('CREATE UNIQUE INDEX users_namespace_email_unique ON users(identity_namespace, email)');
            DB::statement('DROP INDEX users_email_unique');
        });
    }

    public function down(): void
    {
        // Never erase account binding or delete equal-email principals for rollback.
        if (DB::table('users')->where('principal_type', '!=', 'legacy')->exists()) {
            throw new RuntimeException('Identity rollback would discard account semantics; preserve data and use a reviewed forward correction.');
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
ALTER TABLE users
ADD UNIQUE KEY users_email_unique (email),
DROP FOREIGN KEY users_identity_organization_fk,
DROP CHECK users_identity_shape_check,
DROP CHECK users_identity_email_check,
DROP INDEX users_namespace_email_unique,
DROP INDEX users_public_id_unique,
DROP INDEX users_id_organization_unique,
DROP COLUMN identity_namespace,
DROP COLUMN principal_type,
DROP COLUMN public_id,
DROP COLUMN organization_id
SQL);

            return;
        }
        DB::transaction(function (): void {
            DB::statement('CREATE UNIQUE INDEX users_email_unique ON users(email)');
            foreach (['users_namespace_email_unique', 'users_public_id_unique', 'users_id_organization_unique'] as $index) {
                DB::statement("DROP INDEX $index");
            }
            foreach (['identity_namespace', 'principal_type', 'public_id', 'organization_id'] as $column) {
                DB::statement("ALTER TABLE users DROP COLUMN $column");
            }
        });
    }
};
