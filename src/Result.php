<?php

namespace Pr3slaff\Psst;

final readonly class Result
{
    public function __construct(public string $status, public string $eventId) {}
    public function accepted(): bool { return $this->status === 'accepted'; }
    public function queued(): bool { return $this->status === 'queued'; }
    public function failed(): bool { return $this->status === 'failed'; }
}
