<?php

namespace Tests\Integration\MySql;

use App\Actions\Documents\QuarantineTestFile;
use App\Authorization\TenantContext;
use App\Domain\Documents\Intake\IntakeRules;
use App\Domain\Documents\Intake\ScanResult;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Tests\Feature\Documents\QuarantineIntakeTest;

/** Reuses the complete file/scan suite on an identity-verified disposable MySQL server. */
class QuarantineIsolationTest extends QuarantineIntakeTest
{
    private bool $installedCiIdentity = false;

    public function createApplication(): Application
    {
        if (file_exists(dirname(__DIR__, 3).'/bootstrap/cache/config.php')) {
            throw new RuntimeException('Refuse cached database configuration.');
        }
        $app = parent::createApplication();
        $db = $app['db']->connection();
        $uuid = getenv('MVP_FIXTURE_MYSQL_UUID');
        // Before RefreshDatabase can migrate: do not adopt a development/shared database.
        if (getenv('MYSQL_TESTS_ENABLED') !== '1' || ! $app->environment('testing')
            || $db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== 'quality_platform_ci'
            || $app['config']->get('database.connections.mysql.url')) {
            throw new RuntimeException('An explicitly owned disposable MySQL fixture is required.');
        }
        $actual = $db->selectOne('SELECT DATABASE() AS name, @@server_uuid AS uuid, VERSION() AS version');
        // The unchanged GitHub workflow owns its disposable MySQL service. Local runs
        // must supply the UUID captured by their separately audited container runner.
        if ($uuid === false && getenv('GITHUB_ACTIONS') === 'true') {
            $uuid = $actual->uuid;
            putenv('MVP_FIXTURE_MYSQL_UUID='.$uuid);
            $this->installedCiIdentity = true;
        }
        if (! is_string($uuid) || ! preg_match('/\A[0-9a-f-]{36}\z/', $uuid)) {
            throw new RuntimeException('A verified server UUID is required.');
        }
        if ($actual->name !== 'quality_platform_ci' || ! str_starts_with($actual->version, '8.4.') || ! hash_equals($uuid, $actual->uuid)) {
            throw new RuntimeException('Wrong actual MySQL fixture identity.');
        }

        return $app;
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->installedCiIdentity) {
                putenv('MVP_FIXTURE_MYSQL_UUID');
            }
        }
    }

    public function test_wrong_owned_server_identity_cannot_write_quarantine_files(): void
    {
        $member = OrganizationMembership::factory()->create();
        $original = getenv('MVP_FIXTURE_MYSQL_UUID');
        putenv('MVP_FIXTURE_MYSQL_UUID=00000000-0000-0000-0000-000000000000');
        try {
            app(TenantContext::class)->run($member->user, $member->organization_id,
                fn () => app(QuarantineTestFile::class)->execute($member->user, UploadedFile::fake()->createWithContent('test.png', 'not read'), new IntakeRules(1024, ['png' => ['image/png']]), Storage::disk('quarantine-tests'), fn () => ScanResult::Clean));
            $this->fail('Unowned fixture accepted.');
        } catch (LogicException) {
            $this->assertSame([], Storage::disk('quarantine-tests')->allFiles());
        } finally {
            putenv('MVP_FIXTURE_MYSQL_UUID='.$original);
        }
    }

    public function test_foreign_institution_context_cannot_write_or_obtain_a_receipt(): void
    {
        $member = OrganizationMembership::factory()->create();
        $foreign = Organization::factory()->create();
        try {
            app(TenantContext::class)->run($member->user, $foreign->id,
                fn () => app(QuarantineTestFile::class)->execute($member->user, UploadedFile::fake()->createWithContent('test.png', 'not read'), new IntakeRules(1024, ['png' => ['image/png']]), Storage::disk('quarantine-tests'), fn () => ScanResult::Clean));
            $this->fail('Foreign tenant context accepted.');
        } catch (AuthorizationException) {
            $this->assertSame([], Storage::disk('quarantine-tests')->allFiles());
            $this->assertDatabaseCount('documents', 0);
        }
    }
}
