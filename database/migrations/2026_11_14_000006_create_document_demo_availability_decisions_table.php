<?php

use App\Support\ClosureMigrationGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'document_demo_availability_decisions';

    public function up(): void
    {
        ClosureMigrationGuards::assertTarget();
        if (Schema::hasTable(self::TABLE) || Schema::hasIndex('document_intakes', 'intake_demo_exact_scope')
            || Schema::hasIndex('document_file_operation_assignments', 'df_grant_demo_authority')) {
            throw new RuntimeException('Existing demo availability state requires reviewed recovery.');
        }
        $create = function (): void {
            Schema::table('document_intakes', fn ($t) => $t->unique(['organization_id', 'fixture_id', 'id', 'document_id', 'version_id'], 'intake_demo_exact_scope'));
            try {
                Schema::table('document_file_operation_assignments', fn ($t) => $t->unique(['organization_id', 'fixture_id', 'id', 'grantee_id', 'operation', 'document_id', 'version_id'], 'df_grant_demo_authority'));
            } catch (Throwable $error) {
                Schema::table('document_intakes', fn ($t) => $t->dropUnique('intake_demo_exact_scope'));
                throw $error;
            }
            $mysql = DB::getDriverName() === 'mysql';
            $identity = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT' : 'INTEGER';
            $fixture = $mysql ? 'CHAR(32) CHARACTER SET ascii COLLATE ascii_bin' : 'CHAR(32)';
            $fixtureCheck = $mysql ? "CHAR_LENGTH(fixture_id)=32 AND fixture_id NOT REGEXP '[^0-9a-f]'" : "length(fixture_id)=32 AND fixture_id NOT GLOB '*[^0-9a-f]*'";
            $generated = $mysql ? 'VARCHAR(32) GENERATED ALWAYS AS' : 'VARCHAR(32) GENERATED ALWAYS AS';
            $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
            try {
                DB::statement('CREATE TABLE '.self::TABLE." (
                    id {$identity} PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE,
                    organization_id BIGINT UNSIGNED NOT NULL, fixture_id {$fixture} NOT NULL,
                    scenario VARCHAR(40) NOT NULL DEFAULT 'DOCUMENT_LOCAL_001', classification VARCHAR(16) NOT NULL DEFAULT 'TEST_ONLY',
                    document_id BIGINT UNSIGNED NOT NULL, version_id BIGINT UNSIGNED NOT NULL, intake_id BIGINT UNSIGNED NOT NULL,
                    reviewer_id BIGINT UNSIGNED NOT NULL, authority_id BIGINT UNSIGNED NOT NULL,
                    authority_operation {$generated} ('release_demo_unscanned') STORED,
                    state VARCHAR(32) NOT NULL, request_key CHAR(26) NOT NULL, reason TEXT NOT NULL, created_at DATETIME NOT NULL,
                    CONSTRAINT demo_availability_tenant UNIQUE(organization_id,id),
                    CONSTRAINT demo_availability_version UNIQUE(organization_id,fixture_id,document_id,version_id),
                    CONSTRAINT demo_availability_request UNIQUE(organization_id,fixture_id,reviewer_id,request_key),
                    CONSTRAINT demo_availability_fixture CHECK({$fixtureCheck}),
                    CONSTRAINT demo_availability_class CHECK(scenario='DOCUMENT_LOCAL_001' AND classification='TEST_ONLY'),
                    CONSTRAINT demo_availability_state CHECK(state IN('available_demo_unscanned','denied_demo_unscanned')),
                    CONSTRAINT demo_availability_reason CHECK(length(reason)>0),
                    CONSTRAINT demo_availability_intake FOREIGN KEY(organization_id,fixture_id,intake_id,document_id,version_id)
                        REFERENCES document_intakes(organization_id,fixture_id,id,document_id,version_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT demo_availability_reviewer FOREIGN KEY(reviewer_id,organization_id) REFERENCES users(id,organization_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                    CONSTRAINT demo_availability_authority FOREIGN KEY(organization_id,fixture_id,authority_id,reviewer_id,authority_operation,document_id,version_id)
                        REFERENCES document_file_operation_assignments(organization_id,fixture_id,id,grantee_id,operation,document_id,version_id) ON DELETE RESTRICT ON UPDATE RESTRICT
                ){$engine}");
            } catch (Throwable $error) {
                Schema::table('document_file_operation_assignments', fn ($t) => $t->dropUnique('df_grant_demo_authority'));
                Schema::table('document_intakes', fn ($t) => $t->dropUnique('intake_demo_exact_scope'));
                throw $error;
            }
        };
        DB::getDriverName() === 'sqlite' ? DB::transaction($create) : $create();
    }

    public function down(): void
    {
        ClosureMigrationGuards::assertRollbackSafe();
        if (! Schema::hasTable(self::TABLE) || ! DB::table('migrations')->where('migration', pathinfo(__FILE__, PATHINFO_FILENAME))->exists()
            || ! Schema::hasIndex('document_intakes', 'intake_demo_exact_scope') || ! Schema::hasIndex('document_file_operation_assignments', 'df_grant_demo_authority')
            || DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('Unknown or populated availability state; refuse reverse DDL.');
        }
        Schema::drop(self::TABLE);
        Schema::table('document_file_operation_assignments', fn ($t) => $t->dropUnique('df_grant_demo_authority'));
        Schema::table('document_intakes', fn ($t) => $t->dropUnique('intake_demo_exact_scope'));
    }
};
