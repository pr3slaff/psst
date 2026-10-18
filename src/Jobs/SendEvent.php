<?php

namespace Pr3slaff\Psst\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Pr3slaff\Psst\Psst;
use Pr3slaff\Psst\Transport;

final class SendEvent implements ShouldQueue
{
    use Queueable, InteractsWithQueue;
    public int $tries = 0;
    public int $timeout = 5;
    public function __construct(public readonly array $payload, public readonly int $deadline) {}
    public function retryUntil(): \DateTimeImmutable { return (new \DateTimeImmutable)->setTimestamp($this->deadline); }
    public function handle(Transport $transport): void
    {
        try {
            if (time() >= $this->deadline) { $this->delete(); return; }
            $result = $transport->send($this->payload);
            if ($result['accepted']) { return; }
            if (!$result['transient']) { app(Psst::class)->diagnose('permanent_delivery_failure', $this->payload['event_id']); $this->delete(); return; }
            $delay = max($result['retry_after'], min(3600, 5 * 2 ** min(10, max(0, $this->attempts() - 1))));
            if (time() + $delay >= $this->deadline) { $this->delete(); return; }
            $this->release($delay);
        } catch (\Throwable) { try { app(Psst::class)->diagnose('queue_delivery_failed', $this->payload['event_id']); $this->delete(); } catch (\Throwable) {} }
    }
    public function failed(?\Throwable $exception): void { try { app(Psst::class)->diagnose('queue_delivery_failed', $this->payload['event_id']); } catch (\Throwable) {} }
}
