<?php

namespace Tests\Integration\Redis;

use App\Models\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RedisInfrastructureTest extends TestCase
{
    use DatabaseMigrations;

    private string $prefix;

    private array $keys = [];

    private array $queues = [];

    public function createApplication(): Application
    {
        if (file_exists(dirname(__DIR__, 3).'/bootstrap/cache/config.php')) {
            throw new RuntimeException('Clear cached configuration before Redis integration tests.');
        }
        if (getenv('REDIS_TESTS_ENABLED') !== '1' || ! extension_loaded('redis')) {
            throw new RuntimeException('Real Redis integration requires REDIS_TESTS_ENABLED=1 and PhpRedis.');
        }

        $this->prefix = 'quality:testing:integration:';
        $app = parent::createApplication();
        if (! $app->environment('testing') || $app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Redis integration requires isolated in-memory SQLite.');
        }

        return $app;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->app) {
                foreach ($this->keys as $key) {
                    Cache::store('redis')->forget($key);
                }
                foreach ($this->queues as $queue) {
                    // Delete only exact keys of this suite's unique queue, never clear/flush.
                    Redis::connection()->del('queues:'.$queue, 'queues:'.$queue.':notify', 'queues:'.$queue.':reserved', 'queues:'.$queue.':delayed');
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_real_connection_cache_round_trip_namespace_and_invalidation(): void
    {
        $this->assertTrue((bool) Redis::connection('cache')->ping());
        $this->assertSame($this->prefix, Redis::connection('cache')->client()->getOption(\Redis::OPT_PREFIX));
        $key = $this->key('round-trip');
        $this->assertTrue(Cache::put($key, 'synthetic-value', 30));
        $this->assertSame('synthetic-value', Cache::get($key));

        // A separate client without automatic prefix sees the exact physical key.
        $config = config('database.redis.cache');
        $raw = new \Redis;
        try {
            $raw->connect($config['host'], (int) $config['port'], 2);
            if (! empty($config['password'])) {
                $raw->auth(empty($config['username']) ? $config['password'] : [$config['username'], $config['password']]);
            }
            $raw->select((int) $config['database']);
            $physical = $this->prefix.Cache::store('redis')->getStore()->getPrefix().$key;
            $this->assertTrue((bool) $raw->exists($physical));
            $this->assertFalse((bool) $raw->exists($key));
            $this->assertGreaterThan(0, $raw->ttl($physical));
            $this->assertTrue(Cache::forget($key));
            $this->assertFalse((bool) $raw->exists($physical));
        } finally {
            $raw->close();
        }
    }

    public function test_real_ttl_expires(): void
    {
        $key = $this->key('expiration');
        Cache::put($key, 'expires', 1);
        $this->assertSame('expires', Cache::get($key));
        $deadline = microtime(true) + 4;
        do {
            usleep(100000);
            $value = Cache::get($key);
        } while ($value !== null && microtime(true) < $deadline);
        $this->assertNull($value, 'Redis must actually expire the key.');
    }

    public function test_cache_operations_make_no_sql_writes_or_organization_changes(): void
    {
        $organization = Organization::factory()->create();
        $before = $organization->fresh()->getAttributes();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $key = $this->key('no-database-content');
        Cache::put($key, 'synthetic-infrastructure-fixture', 30);
        Cache::get($key);
        Cache::forget($key);
        $this->assertSame([], $queries, 'Redis cache must not read or write SQL.');
        $this->assertSame($before, $organization->fresh()->getAttributes());
        $this->assertSame(1, Organization::count());
    }

    public function test_redis_queue_enqueues_only_after_commit_and_discards_rollback(): void
    {
        $this->assertTrue(config('queue.connections.redis.after_commit'));
        $queue = 'phase03a-'.Str::uuid();
        $this->queues[] = $queue;

        DB::beginTransaction();
        Queue::connection('redis')->push(new InfrastructureProbeJob, '', $queue);
        $this->assertSame(0, Queue::connection('redis')->size($queue));
        DB::commit();
        $this->assertSame(1, Queue::connection('redis')->size($queue));

        DB::beginTransaction();
        Queue::connection('redis')->push(new InfrastructureProbeJob, '', $queue);
        $this->assertSame(1, Queue::connection('redis')->size($queue));
        DB::rollBack();
        $this->assertSame(1, Queue::connection('redis')->size($queue));
    }

    private function key(string $suffix): string
    {
        $key = 'probe:'.Str::uuid().':'.$suffix;
        $this->keys[] = $key;

        return $key;
    }
}

// A test-only payload; no worker is started and no domain work is implemented.
class InfrastructureProbeJob implements ShouldQueue
{
    public function handle(): void {}
}
