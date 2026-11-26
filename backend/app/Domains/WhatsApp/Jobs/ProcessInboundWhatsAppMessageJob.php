<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Jobs;

use App\Domains\WhatsApp\Services\InboundMessageProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessInboundWhatsAppMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $whatsappMessageId,
    ) {}

    public function handle(InboundMessageProcessor $processor): void
    {
        $processor->process($this->whatsappMessageId);
    }
}
