<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER identity_membership_update BEFORE UPDATE ON organization_memberships FOR EACH ROW
BEGIN
 IF EXISTS (SELECT 1 FROM users WHERE id = NEW.user_id AND
  (principal_type = 'platform' OR (principal_type = 'institution' AND organization_id <> NEW.organization_id))) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Account institution mismatch';
 END IF;
END
SQL);
        } else {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER identity_membership_update BEFORE UPDATE ON organization_memberships
WHEN EXISTS (SELECT 1 FROM users WHERE id = NEW.user_id AND
 (principal_type = 'platform' OR (principal_type = 'institution' AND organization_id <> NEW.organization_id)))
BEGIN SELECT RAISE(ABORT, 'Account institution mismatch'); END
SQL);
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS identity_membership_update');
    }
};
