<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER identity_binding_update BEFORE UPDATE ON users FOR EACH ROW
BEGIN
 IF NOT (NEW.principal_type <=> OLD.principal_type) OR NOT (NEW.organization_id <=> OLD.organization_id)
  OR NOT (NEW.public_id <=> OLD.public_id) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Account binding is immutable; explicit migration required';
 END IF;
END
SQL);
        } else {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER identity_binding_update BEFORE UPDATE ON users
WHEN NEW.principal_type IS NOT OLD.principal_type OR NEW.organization_id IS NOT OLD.organization_id
 OR NEW.public_id IS NOT OLD.public_id
BEGIN SELECT RAISE(ABORT, 'Account binding is immutable; explicit migration required'); END
SQL);
        }
    }

    public function down(): void
    {
        if (DB::table('users')->where('principal_type', '!=', 'legacy')->exists()) {
            throw new RuntimeException('Account principals exist; preserve immutable bindings and use a reviewed forward correction.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS identity_binding_update');
    }
};
