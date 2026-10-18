<?php

namespace Pr3slaff\Psst;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Str;
use Pr3slaff\Psst\Jobs\SendEvent;

class Psst
{
    private static bool $diagnosing = false;
    public function __construct(private bool $immediate = false) {}
    public function force(): self { return new self(true); }
    public function info(string $message, array $context = []): Result { return $this->send('info', $message, $context); }
    public function warning(string $message, array $context = []): Result { return $this->send('warning', $message, $context); }
    public function success(string $message, array $context = []): Result { return $this->send('success', $message, $context); }
    public function danger(string $message, array $context = []): Result { return $this->send('danger', $message, $context); }
    public function metric(string $key, string $label, callable $callback, array $options = []): void
    {
        try { app(Metrics::class)->register($key, $label, $callback, $options); } catch (\Throwable) { $this->diagnose('metric_registration_failed', ''); }
    }
    public function diagnose(string $reason, string $id): void
    {
        if (self::$diagnosing) { return; }
        self::$diagnosing = true;
        try {
            $callback = config('psst.diagnostics');
            if (is_callable($callback)) { $callback($reason, $id); }
            else { app('log')->warning('Psst delivery diagnostic', ['reason' => $reason, 'event_id' => $id]); }
        } catch (\Throwable) {} finally { self::$diagnosing = false; }
    }
    private function send(string $level, string $message, array $context): Result
    {
        $id = '';
        try {
            $id = (string) Str::uuid();
            $payload = (new Normalizer)->normalize($id, $level, $message, $context, (array) config('psst.redact_fields', []));
            if (!$this->immediate && config('psst.mode') === 'queue') { return $this->enqueue($payload); }
            if (app(Transport::class)->send($payload)['accepted']) { return new Result('accepted', $id); }
            $this->diagnose('delivery_failed', $id);
            if (config('psst.fallback_to_queue', false)) { return $this->enqueue($payload); }
        } catch (\Throwable) { $this->diagnose('internal_failure', $id); }
        return new Result('failed', $id);
    }
    private function enqueue(array $payload): Result
    {
        try {
            $connection = config('psst.queue_connection') ?: config('queue.default');
            $driver = config("queue.connections.{$connection}.driver");
            if (!is_string($connection) || !in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true) || app('queue')->connection($connection) instanceof SyncQueue) {
                $this->diagnose('asynchronous_queue_required', $payload['event_id']);
                return new Result('failed', $payload['event_id']);
            }
            $job = (new SendEvent($payload, time() + 86400))->onConnection($connection)->onQueue(config('psst.queue', 'psst'))->beforeCommit();
            // Dispatch explicitly, never PendingDispatch's destructor outside the catch boundary.
            $dispatch = function () use ($job, $payload): void {
                try { app(Dispatcher::class)->dispatch($job); }
                catch (\Throwable) { $this->diagnose('enqueue_failed', $payload['event_id']); }
            };
            if (app()->bound('db.transactions') && app('db.transactions')->callbackApplicableTransactions()->isNotEmpty()) {
                // Own the callback's catch boundary: enqueue failures after commit cannot escape.
                app('db.transactions')->addCallback($dispatch);
            } else {
                app(Dispatcher::class)->dispatch($job);
            }
            return new Result('queued', $payload['event_id']);
        } catch (\Throwable) { $this->diagnose('enqueue_failed', $payload['event_id']); return new Result('failed', $payload['event_id']); }
    }
}
