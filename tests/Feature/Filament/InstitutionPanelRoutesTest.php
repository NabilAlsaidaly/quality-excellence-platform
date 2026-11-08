<?php

namespace Tests\Feature\Filament;

use App\Filament\Institution\InstitutionPanelEnvironment;
use App\Filament\Institution\InstitutionPanelSession;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InstitutionPanelRoutesTest extends TestCase
{
    private function process46(array $arguments, string $environment, string $cache): Process
    {
        $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.sys_get_temp_dir(), ...$arguments], base_path(), [
            'APP_ENV' => $environment, 'APP_DEBUG' => 'true', 'APP_URL' => 'http://localhost', 'APP_ROUTES_CACHE' => '.local-tools/tmp/'.basename($cache),
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
            'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ]);
        $process->setTimeout(45);
        $this->assertSame(0, $process->run(), $process->getErrorOutput().$process->getOutput());

        return $process;
    }

    public function test_uncached_and_cached_four_environment_routes_and_local_cache_on_production_remain_closed(): void
    {
        $directory = base_path('.local-tools/tmp');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $receipt = [];
        $cacheFiles = [];
        try {
            foreach (['local', 'testing', 'staging', 'production'] as $environment) {
                $cache = $directory.'/issue46-routes-'.$environment.'-'.bin2hex(random_bytes(5)).'.php';
                $cacheFiles[] = $cache;
                foreach ([false, true] as $cached) {
                    if ($cached) {
                        $this->process46(['artisan', 'route:cache'], $environment, $cache);
                    }
                    $routes = json_decode($this->process46(['artisan', 'route:list', '--json'], $environment, $cache)->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                    $panel = array_values(array_filter($routes, fn ($route) => str_starts_with($route['uri'], 'institution/workspace')));
                    $this->assertCount(4, $panel);
                    foreach ($panel as $route) {
                        $this->assertContains(InstitutionPanelEnvironment::class, $route['middleware']);
                        $this->assertContains(InstitutionPanelSession::class, $route['middleware']);
                    }
                    $this->assertSame([], array_values(array_filter($routes, fn ($route) => str_starts_with($route['uri'], 'platform'))));
                    $receipt[$environment][$cached ? 'cached' : 'uncached'] = count($panel);
                }
                if ($environment === 'local') {
                    $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(Illuminate\Http\Request::create('http://localhost/institution/workspace','GET'));
echo json_encode(['status'=>$response->getStatusCode(),'body'=>$response->getContent()]);
PHP;
                    foreach (['staging', 'production'] as $runtime) {
                        $result = json_decode($this->process46(['-r', $code], $runtime, $cache)->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                        $this->assertSame(404, $result['status']);
                        $this->assertStringNotContainsString('Stack trace', $result['body']);
                    }
                }
            }
        } finally {
            foreach ($cacheFiles as $file) {
                if (is_file($file)) {
                    unlink($file); // Exact random test-owned cache file, never user route cache.
                }
            }
        }
        $this->assertFalse(Route::has('filament.platform.pages.dashboard'));
    }
}
