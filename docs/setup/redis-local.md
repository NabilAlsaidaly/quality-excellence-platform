# Local Redis and Phase 03A integration tests

MySQL remains the persistent source of truth. Redis is infrastructure for cache and future queues; it neither grants permissions nor stores approved institutional content in this phase. Session storage remains `database`. No workers, domain jobs or production configuration are introduced.

## Windows 11 / Docker Desktop

Enable Docker Desktop's Linux engine. PHP 8.4 must have PhpRedis (`php --ri redis`), pdo_mysql and pdo_sqlite; no Predis package is needed. The development Compose service pins the official `redis:8.2.11-alpine` image, binds only loopback, has a health check and a local named AOF volume. It is unauthenticated and intended only for local synthetic development; do not publish the port or reuse it for production. AOF is a local queue durability aid, not a MySQL backup or approved production recovery policy.

```sh
docker compose -f compose.redis.yml up -d --wait
docker compose -f compose.redis.yml ps
docker compose -f compose.redis.yml exec redis redis-cli ping
```

Expected PONG and healthy status. Configure ignored `.env` (safe defaults are in `.env.example`):

```dotenv
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=database
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
```

Then `php artisan config:clear` and use `php artisan tinker` to evaluate `Illuminate\Support\Facades\Redis::connection()->ping()`. No password/key should be copied into Git. A configured Redis outage raises connection/operation errors; it does not silently fall back to another backend. `/up` is Laravel liveness, not a Redis readiness check. Never claim the service is healthy from `/up` alone.

Stop without deleting data:

```sh
docker compose -f compose.redis.yml stop
```

`docker compose -f compose.redis.yml down` also removes this Compose project's containers/network, retaining its named volume. Do not use `down -v`, `FLUSHDB` or `FLUSHALL` on shared data. For port conflicts use a separate local port, for example PowerShell `$env:REDIS_LOCAL_PORT='6387'` before `up`, then set `.env` REDIS_PORT=6387. REDIS_LOCAL_PORT changes Docker's published port; REDIS_PORT changes the PHP client. If Docker reports pipe/engine errors, start Docker Desktop; if a connection fails, verify port, container health, PHP extension and cached config. Do not disable TLS/security settings to make a check pass.

## Namespace, TTL and invalidation policy

Default Redis prefix is `quality:{APP_ENV}:`, cache prefix `cache:`. An eventual permitted tenant key would therefore be `quality:local:cache:tenant:{organization_ulid}:resource:{resource_ulid}:v1`. Every future cache use must declare an explicit finite TTL and invalidation after committed source changes (or version-key replacement), and retain MySQL reads as authoritative. Validate actor/tenant/permissions before constructing keys. Do not cache evidence, document contents, permission decisions, privileged tokens or other unapproved tenant data. No generic cache service or tenant cache is implemented here. Override REDIS_PREFIX/CACHE_PREFIX only if preserving project/environment isolation.

Redis queues use DB 0; cache uses DB 1. `queue.connections.redis.after_commit=true` is passed by the installed RedisConnector to RedisQueue; Laravel's Queue::enqueueUsing registers a transaction-manager callback. A real integration test verifies no push before commit and no push on rollback. No application jobs or queue workers are added. Future jobs must carry explicit actor/organization context and reauthorize; a queue message is not authority. Database cache/jobs tables are retained for framework compatibility; they are not removed merely because Redis is configurable.

## Independent test suites

General `php artisan test` still uses `phpunit.xml`: SQLite in memory, array cache/session, sync queues. Integration tests live outside its Feature/Unit paths and are never silently skipped. Clear any config cache first. Each suite exits with an error if its explicitly required infrastructure or opt-in is absent.

Real Redis, PowerShell:

```powershell
$env:REDIS_TESTS_ENABLED='1'
$env:REDIS_HOST='127.0.0.1'
$env:REDIS_PORT='6379' # use your published test port
php vendor/bin/phpunit --configuration phpunit.redis.xml
Remove-Item Env:REDIS_TESTS_ENABLED
```

Redis tests force a testing-only prefix and use UUID keys/queues, delete only those exact keys, verify physical namespace and TTL with a separate PhpRedis client, assert zero SQL during cache operations and preserve an in-memory organization sentinel. They do not connect to MySQL; CI's separate MySQL suite proves MySQL constraints. The queue payload is test-only and never processed by a worker. No flush command is used. These local-only fixtures do not constitute end-to-end tenant authorization.

## Disposable MySQL tests

**Destructive suite:** use only a separately provisioned, disposable database named exactly `quality_platform_ci`. Never point normal development/production credentials at this suite. The test base requires MYSQL_TESTS_ENABLED=1, APP_ENV=testing, driver mysql, exact configured AND actual database name, no DB_URL override and no cached config before Laravel's DatabaseMigrations hook can execute. The suite uses migrate:fresh and rollback; it deletes all contents of that test database. General PHPUnit never accesses it.

Example isolated local container (choose an unused name/loopback port; these credentials are disposable fixtures only):

```sh
docker run --detach --rm --name quality-phase03a-mysql --publish 127.0.0.1:33068:3306 --env MYSQL_DATABASE=quality_platform_ci --env MYSQL_USER=quality_platform_ci --env MYSQL_PASSWORD=disposable-ci-password --env MYSQL_ROOT_PASSWORD=disposable-ci-root-password mysql:8.4
docker exec quality-phase03a-mysql mysqladmin ping --silent
```

MySQL cannot use AUTO_INCREMENT id in a CHECK expression. Two narrow row-local triggers reject a unit as its own parent even through raw SQL. Use an approved migration operator capable of creating triggers under the server's existing binary-logging policy. Do not change global settings or grant administration privileges to the runtime application account. For the disposable service above, the pre-existing root fixture supplies migration privileges; normal application credentials remain database-scoped. The integration suite also uses the restricted fixture to reproduce real error 1419 under unchanged MySQL defaults. Full multi-level cycle prevention remains deferred.

```powershell
$env:MYSQL_TESTS_ENABLED='1'
$env:DB_HOST='127.0.0.1'
$env:DB_PORT='33068'
$env:DB_USERNAME='root' # disposable container migration operator only
$env:DB_PASSWORD='disposable-ci-root-password'
php vendor/bin/phpunit --configuration phpunit.mysql.xml
Remove-Item Env:MYSQL_TESTS_ENABLED,Env:DB_HOST,Env:DB_PORT,Env:DB_USERNAME,Env:DB_PASSWORD
```

Use a dedicated shell for integration variables so normal commands cannot accidentally target the test schema. Once finished, stop only the container you created: `docker stop quality-phase03a-mysql` (its --rm flag removes it). Do not stop other containers or erase existing volumes. MySQL migrations are additive for existing users and introduce no data backfill; review backups/rollbacks separately before any shared environment migration. The new tables' restrictive foreign keys are not permission checks.

## Trigger migration deployment prerequisites and recovery

Before any shared rollout, the engineering lead/migration operator must verify:

- MySQL 8.4/InnoDB with required trigger support, and a tested backup/recovery plan. This task authorizes no deployment.
- A separate migration account with schema CREATE/ALTER/REFERENCES and TRIGGER privileges; SELECT, DROP and LOCK TABLES are required for compensating cleanup. Creating triggers with binary logging enabled may additionally require an administrative privilege under MySQL/provider policy (error 1419). Inspect `SHOW GRANTS` and `SELECT @@GLOBAL.log_bin, @@GLOBAL.log_bin_trust_function_creators` read-only. No migration, test or CI step changes either setting.
- The provider permits trigger creation under those existing settings. If it does not, deployment is blocked pending an approved architecture/operations decision. Do not skip the triggers, turn off binary logging or grant broad privileges to the runtime user to manufacture success. Container root credentials here are test fixtures, not a production deployment prescription.
- HTTP writes, workers, schedulers and external writers are paused, and only one migration operator runs. Application maintenance mode alone does not stop background/external writers. Keep writes paused until migrations and integrity checks pass.
- The trigger definer is the creating migration account (no explicit cross-environment DEFINER is hard-coded). Its account and required trigger-activation privileges must remain valid; managed-service imports, replicas and definer removal need separate operator review.

The units migration now compensates a trigger-installation exception **only after its own table creation succeeds**. It obtains a WRITE table lock, checks the new table is empty, then drops that table (which removes any first trigger) and rethrows the original failure. The lock prevents a write racing between the empty check and DROP. Existing tables never enter this cleanup path. Unexpected rows are preserved. If LOCK/SELECT/DROP/UNLOCK fails, it raises an explicit recovery error with the original trigger failure as its cause; it never marks the migration successful or disables an invariant. After successful cleanup and correcting migration-account prerequisites, ordinary `php artisan migrate` retries normally; no migrate:fresh or manually fabricated migration-log entry is needed.

MySQL commits these DDL statements individually, so this is compensating recovery, not transaction-wide atomic DDL. Process termination, connection loss, failover, earlier table/FK creation failure, cleanup privilege loss or unsupported locking can still require operator intervention. Keep the application offline and inspect `migrations`, `information_schema.TRIGGERS` and the actual table before retrying. The patch intentionally refuses to adopt/drop a pre-existing table left by an older failed attempt. If such a table exists with no migration entry, the operator must establish its provenance, dependencies and contents from a backup: authorize removal only of a verified empty failed-attempt table, or reconcile/preserve rows under a reviewed recovery plan. Never blindly drop a populated table, use migrate:fresh on shared data, or mark the migration complete without checking both triggers and the foreign keys. This correction does not modify already-recorded successful migrations.

Official references: [Laravel Redis](https://laravel.com/docs/13.x/redis), [queues and transactions](https://laravel.com/docs/13.x/queues#jobs-and-database-transactions), [MySQL foreign keys](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html), [CHECK limitations](https://dev.mysql.com/doc/refman/8.4/en/create-table-check-constraints.html), [trigger binary-logging requirements](https://dev.mysql.com/doc/refman/8.4/en/stored-programs-logging.html), [official Redis image](https://hub.docker.com/_/redis).
