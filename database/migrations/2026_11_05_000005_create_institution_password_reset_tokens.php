<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE institution_password_reset_tokens (
 account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 organization_id BIGINT UNSIGNED NOT NULL,
 token VARCHAR(255) NOT NULL,
 credential_hash CHAR(64) NOT NULL,
 created_at TIMESTAMP NOT NULL,
 CONSTRAINT institution_reset_account_fk FOREIGN KEY (account_id, organization_id)
 REFERENCES users(id, organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT
)
SQL.(DB::getDriverName() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    }

    public function down(): void
    {
        // Tokens are live authentication state, not disposable migration fixtures.
        if (DB::table('users')->where('principal_type', '!=', 'legacy')->exists()
            || DB::table('institution_password_reset_tokens')->exists()) {
            throw new RuntimeException('Account identity or recovery state exists; rollback requires reviewed account cutover.');
        }
        Schema::drop('institution_password_reset_tokens');
    }
};
