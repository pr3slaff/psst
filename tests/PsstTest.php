<?php

namespace Pr3slaff\Psst\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase;
use Pr3slaff\Psst\Facades\Psst;
use Pr3slaff\Psst\Jobs\SendEvent;
use Pr3slaff\Psst\Normalizer;
use Pr3slaff\Psst\PsstServiceProvider;
use Pr3slaff\Psst\Transport;

class PsstTest extends TestCase
{
    protected function getPackageProviders($app): array { return [PsstServiceProvider::class]; }
    protected function defineEnvironment($app): void
    {
        $app['config']->set('psst.endpoint', 'https://psst.example/api/v1/ingest');
        $app['config']->set('psst.key', 'ingestion-test');
        $app['config']->set('psst.health_key', 'health-test');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('queue.connections.database', ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false]);
    }
    private function acknowledge(): void
    {
        Http::fake(fn ($request) => Http::response(['data' => ['id' => 'server-id', 'event_id' => $request['event_id'], 'duplicate' => false]], 201));
    }
    private function queueTable(): void
    {
        DB::getSchemaBuilder()->create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id'); $table->string('queue'); $table->longText('payload'); $table->unsignedTinyInteger('attempts'); $table->unsignedInteger('reserved_at')->nullable(); $table->unsignedInteger('available_at'); $table->unsignedInteger('created_at');
        });
        config(['psst.queue_connection' => 'database', 'psst.mode' => 'queue']);
    }
    public function test_all_levels_have_one_attempt_and_stable_wire_id(): void
    {
        $this->acknowledge();
        foreach (['info', 'warning', 'success', 'danger'] as $level) {
            $result = Psst::$level('Здравей', ['Password' => 'sensitive']);
            $this->assertTrue($result->accepted());
            Http::assertSent(fn ($request) => $request['event_id'] === $result->eventId && $request['level'] === $level && $request['context']['Password'] === '[REDACTED]');
        }
        Http::assertSentCount(4);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $result->eventId);
    }
    public function test_internal_and_diagnostic_errors_are_silent_and_no_automatic_retry(): void
    {
        Http::fake(fn () => throw new \RuntimeException('credential-in-exception'));
        config(['psst.diagnostics' => fn () => throw new \RuntimeException('diagnostics broken')]);
        $this->assertTrue(Psst::danger('Problem')->failed());
        $this->assertTrue(Psst::force()->info('Problem')->failed());
    }
    public function test_default_diagnostics_contain_only_safe_reason_and_id_and_callback_recursion_is_bounded(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        Http::fake(fn () => Http::response(['secret' => 'never log this'], 503));
        $result = Psst::info('Private message', ['private' => 'private context']);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->with('Psst delivery diagnostic', ['reason' => 'delivery_failed', 'event_id' => $result->eventId]);
        $calls = 0;
        config(['psst.diagnostics' => function () use (&$calls) { $calls++; Psst::warning('Diagnostic recursion'); }]);
        $this->assertTrue(Psst::info('Original')->failed()); $this->assertSame(1, $calls);
    }
    public function test_permanent_and_transient_results_and_retry_after(): void
    {
        $payload = (new Normalizer)->normalize('id', 'info', 'Message', [], []);
        foreach ([401, 403, 422, 429, 500] as $status) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(fn () => Http::response([], $status, ['Retry-After' => '120']));
            $result = app(Transport::class)->send($payload);
            $this->assertSame(in_array($status, [429, 500]), $result['transient']);
            $this->assertSame(120, $result['retry_after']);
        }
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => Http::response([], 429, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 180)]));
        $result = app(Transport::class)->send($payload);
        $this->assertGreaterThanOrEqual(179, $result['retry_after']);
        $this->assertLessThanOrEqual(180, $result['retry_after']);
    }
    public function test_sync_driver_rejected_and_force_is_immediate(): void
    {
        $this->acknowledge(); config(['psst.mode' => 'queue']);
        $this->assertTrue(Psst::info('Queued')->failed()); Http::assertNothingSent();
        $this->assertTrue(Psst::force()->info('Immediate')->accepted()); Http::assertSentCount(1);
    }
    public function test_database_queue_waits_for_commit_and_rollbacks_discard(): void
    {
        $this->queueTable(); Http::fake();
        DB::beginTransaction();
        $result = Psst::success('Commit me');
        $this->assertTrue($result->queued()); $this->assertSame(0, DB::table('jobs')->count());
        DB::commit(); $this->assertSame(1, DB::table('jobs')->count());
        DB::beginTransaction(); Psst::info('Discard me'); DB::rollBack();
        $this->assertSame(1, DB::table('jobs')->count()); Http::assertNothingSent();
        $queued = json_decode(DB::table('jobs')->value('payload'), true);
        $job = unserialize($queued['data']['command']);
        $this->assertSame($result->eventId, $job->payload['event_id']);
        $this->assertLessThanOrEqual(time() + 86400, $job->deadline);
    }
    public function test_post_commit_enqueue_failure_does_not_escape_commit(): void
    {
        $this->queueTable(); DB::beginTransaction();
        $this->assertTrue(Psst::info('Will be lost')->queued());
        DB::getSchemaBuilder()->drop('jobs');
        DB::commit(); $this->assertSame(0, DB::transactionLevel());
    }
    public function test_fallback_only_when_enabled_and_payload_is_immutable(): void
    {
        $this->queueTable(); config(['psst.mode' => 'sync']); Http::fake(fn () => Http::response([], 503));
        $this->assertTrue(Psst::info('No fallback')->failed()); $this->assertSame(0, DB::table('jobs')->count());
        config(['psst.fallback_to_queue' => true]); $result = Psst::force()->warning('Fallback');
        $this->assertTrue($result->queued());
        $job = unserialize(json_decode(DB::table('jobs')->value('payload'), true)['data']['command']);
        Http::assertSent(fn ($request) => json_encode($request->data()) === json_encode($job->payload));
    }
    public function test_retry_releases_transient_but_deletes_permanent_and_expired(): void
    {
        $payload = (new Normalizer)->normalize('id', 'info', 'Message', [], []);
        Http::fake(fn () => Http::response([], 429, ['Retry-After' => '120']));
        $queueJob = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queueJob->shouldReceive('attempts')->once()->andReturn(3); $queueJob->shouldReceive('release')->once()->with(120);
        $job = new SendEvent($payload, time() + 86400); $job->setJob($queueJob); $job->handle(app(Transport::class));
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => Http::response([], 503));
        $exponential = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $exponential->shouldReceive('attempts')->once()->andReturn(4); $exponential->shouldReceive('release')->once()->with(40);
        $job = new SendEvent($payload, time() + 86400); $job->setJob($exponential); $job->handle(app(Transport::class));
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => Http::response([], 422));
        $queueJob = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class); $queueJob->shouldReceive('delete')->twice();
        $job = new SendEvent($payload, time() + 86400); $job->setJob($queueJob); $job->handle(app(Transport::class));
        $expired = new SendEvent($payload, time() - 1); $expired->setJob($queueJob); $expired->handle(app(Transport::class));
    }
    public function test_normalization_masks_recursively_limits_size_depth_and_avoids_object_hooks(): void
    {
        $object = new class implements \JsonSerializable { public function jsonSerialize(): mixed { throw new \RuntimeException('Must not execute'); } };
        $context = ['nested' => ['TOKEN' => 'hide'], 'model' => $object, 'nan' => NAN];
        $payload = (new Normalizer)->normalize('id', 'info', str_repeat('Ж', 5000), $context, ['token']);
        $this->assertLessThanOrEqual(4000, mb_strlen($payload['message']));
        $this->assertTrue($payload['truncated']); $this->assertSame('[REDACTED]', $payload['context']['nested']['TOKEN']);
        $deep = ['value' => true]; for ($i = 0; $i < 20; $i++) { $deep = ['nested' => $deep]; }
        $payload = (new Normalizer)->normalize('id', 'info', 'Message', $deep, []);
        $this->assertTrue($payload['truncated']); $this->assertStringContainsString('depth limit', json_encode($payload));
        $payload = (new Normalizer)->normalize('id', 'info', 'Message', ['one' => str_repeat('x', 60000), 'two' => str_repeat('x', 60000)], []);
        $this->assertLessThanOrEqual(65536, strlen(json_encode($payload))); $this->assertTrue($payload['truncated']);
        $recursive = []; $recursive['self'] = &$recursive;
        $payload = (new Normalizer)->normalize('id', 'info', 'Recursive', $recursive, []);
        $this->assertTrue($payload['truncated']); $this->assertLessThanOrEqual(65536, strlen(json_encode($payload)));
        $shared = ['x' => str_repeat('x', 60000)];
        for ($i = 0; $i < 7; $i++) { $shared = array_fill(0, 1000, $shared); }
        $payload = (new Normalizer)->normalize('id', 'info', 'Shared references', $shared, []);
        $this->assertTrue($payload['truncated']); $this->assertLessThanOrEqual(65536, strlen(json_encode($payload)));
    }
    public function test_single_health_endpoint_authentication_and_boot_only(): void
    {
        config(['psst.health_key' => null]); $this->getJson('/psst-health')->assertNotFound();
        config(['psst.health_key' => 'health-test']); $this->getJson('/psst-health')->assertUnauthorized();
        $this->withHeader('X-Psst-Key', 'health-test')->getJson('/psst-health')->assertOk()->assertJson(['schema_version' => 1, 'status' => 'ok', 'metrics' => []]);
    }
    public function test_metrics_cache_actual_time_and_preserve_last_good_on_error(): void
    {
        $calls = 0;
        Psst::metric('sales_today', 'Sales today', function () use (&$calls) { $calls++; return 42; }, ['unit' => 'orders']);
        Psst::metric('broken', 'Broken', fn () => throw new \RuntimeException('password=secret'));
        $first = $this->withHeader('X-Psst-Key', 'health-test')->getJson('/psst-health')->assertOk()->json();
        $second = $this->getJson('/psst-health')->assertOk()->json();
        $this->assertSame(1, $calls); $this->assertSame($first['metrics'][0]['computed_at'], $second['metrics'][0]['computed_at']);
        $this->assertSame('Metric unavailable', $second['metrics'][1]['error']); $this->assertTrue($second['metrics'][1]['is_stale']);
        Psst::metric('sales_today', 'Sales today', fn () => throw new \RuntimeException('secret'), ['cache_ttl' => 0]);
        // Expire cached freshness independently of retained good value.
        $cacheKey = 'psst.metric.'.hash('sha256', config('app.name').'|sales_today');
        $cached = app('cache')->get($cacheKey); $cached['expires_at'] = 0; app('cache')->forever($cacheKey, $cached);
        $response = $this->getJson('/psst-health')->assertOk()->json();
        $this->assertSame(42, $response['metrics'][0]['value']); $this->assertTrue($response['metrics'][0]['is_stale']);
        $this->assertSame($first['metrics'][0]['computed_at'], $response['metrics'][0]['computed_at']);
    }
    public function test_metrics_limit_and_non_scalar_isolation(): void
    {
        Psst::metric('bad', 'Bad', fn () => ['nested' => 'table']);
        for ($i = 0; $i < 30; $i++) { Psst::metric('metric_'.$i, 'Metric', fn () => true); }
        $data = $this->withHeader('X-Psst-Key', 'health-test')->getJson('/psst-health')->assertOk()->json();
        $this->assertCount(20, $data['metrics']); $this->assertSame('Metric unavailable', $data['metrics'][0]['error']);
        $this->assertSame('boolean', $data['metrics'][1]['type']);
    }
    public function test_canonical_contract_fixtures_and_transport_timeout_options(): void
    {
        $fixtureRoot = __DIR__.'/../../../docs/contracts/fixtures/';
        if (is_file($fixtureRoot.'ingest.json')) {
            $fixture = json_decode(file_get_contents($fixtureRoot.'ingest.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($fixture, array_replace((new Normalizer)->normalize($fixture['event_id'], $fixture['level'], $fixture['message'], $fixture['context'], []), ['occurred_at' => $fixture['occurred_at']]));
        }
        Http::fake(function ($request, $options) {
            $this->assertSame(1, $options['timeout']); $this->assertSame(1, $options['connect_timeout']);
            $this->assertFalse($options['allow_redirects']);
            return Http::response(['data' => ['id' => 'id', 'event_id' => $request['event_id']]], 201);
        });
        $this->assertTrue(Psst::info('Timeout options')->accepted());
        Psst::metric('sales_today', 'Sales today', fn () => 24, ['unit' => 'orders']);
        $response = $this->withHeader('X-Psst-Key', 'health-test')->getJson('/psst-health')->assertOk()->json();
        if (is_file($fixtureRoot.'health.json')) {
            $healthFixture = json_decode(file_get_contents($fixtureRoot.'health.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(array_keys($healthFixture), array_keys($response));
            $this->assertSame(array_keys($healthFixture['metrics'][0]), array_keys($response['metrics'][0]));
        }
    }
    public function test_real_transport_enforces_one_second_total_timeout(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false); fclose($socket);
        $process = proc_open([PHP_BINARY, '-S', $address, __DIR__.'/timeout-router.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        try {
            $started = false;
            for ($i = 0; $i < 50; $i++) {
                $probe = @stream_socket_client('tcp://'.$address, $errno, $error, 0.05);
                if ($probe) { fclose($probe); $started = true; break; }
                usleep(20000);
            }
            $this->assertTrue($started, 'Loopback fixture must start');
            config(['psst.endpoint' => 'http://'.$address.'/ingest']);
            $begin = microtime(true);
            $this->assertTrue(Psst::info('Real timeout')->failed());
            $elapsed = microtime(true) - $begin;
            $this->assertGreaterThan(0.8, $elapsed);
            $this->assertLessThan(2.5, $elapsed);
        } finally {
            proc_terminate($process); foreach ($pipes as $pipe) { fclose($pipe); } proc_close($process);
        }
    }
}
