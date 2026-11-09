<?php

namespace Tests\Feature\Delivery;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Browser\InstitutionIdentity\Environment;

class InstitutionIdentityBrowserHarnessTest extends TestCase
{
    public function test_fixture_cli_returns_nonzero_without_opt_in_before_any_database_bootstrap(): void
    {
        $root = dirname(__DIR__, 3);
        $process = proc_open([PHP_BINARY, $root.'/tests/Browser/InstitutionIdentity/Fixture.php', 'verify'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root,
            array_replace(getenv(), ['QEP_IDENTITY_JOURNEY' => '']));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(1, proc_close($process));
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('Fixture operation failed', $stderr);
    }

    private function manifest(): array
    {
        return ['namespace' => 'qep-issue53-identity', 'session' => '1234567890abcdef',
            'database' => 'quality_platform_ci', 'db_host' => '127.0.0.1', 'db_port' => 13399,
            'http_host' => '127.0.0.3', 'http_port' => 18530,
            'server_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'];
    }

    #[DataProvider('unsafeTargets')]
    public function test_unsafe_target_or_missing_opt_in_is_rejected_before_bootstrap(array $override, string $optIn): void
    {
        $this->expectException(RuntimeException::class);
        Environment::assertManifest(array_replace($this->manifest(), $override), $optIn);
    }

    public static function unsafeTargets(): array
    {
        return [[['database' => 'quality_platform'], '1234567890abcdef'],
            [['db_host' => 'remote.example.test'], '1234567890abcdef'],
            [['http_host' => '0.0.0.0'], '1234567890abcdef'],
            [['namespace' => 'another-test'], '1234567890abcdef'],
            [['server_uuid' => ''], '1234567890abcdef'],
            [['db_port' => 0], '1234567890abcdef'],
            [[], '']];
    }

    #[DataProvider('unsafeActualIdentities')]
    public function test_actual_database_identity_and_environment_must_match(array $actual, string $environment, string $driver): void
    {
        $this->expectException(RuntimeException::class);
        Environment::assertDatabase($this->manifest(), (object) $actual, $environment, $driver);
    }

    public static function unsafeActualIdentities(): array
    {
        $valid = ['name' => 'quality_platform_ci', 'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'version' => '8.4.13'];

        return [[array_replace($valid, ['name' => 'quality_platform']), 'testing', 'mysql'],
            [array_replace($valid, ['uuid' => '11111111-2222-3333-4444-555555555555']), 'testing', 'mysql'],
            [$valid, 'production', 'mysql'], [$valid, 'testing', 'sqlite'],
            [array_replace($valid, ['version' => '8.0.0']), 'testing', 'mysql']];
    }
}
