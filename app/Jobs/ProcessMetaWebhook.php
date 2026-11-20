<?php

namespace App\Jobs;

use App\Services\Meta\MetaWebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Processing is idempotent, so retries are safe. */
class ProcessMetaWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(public array $payload) {}

    public function handle(MetaWebhookProcessor $processor): void
    {
        $processor->process($this->payload);
    }
}
