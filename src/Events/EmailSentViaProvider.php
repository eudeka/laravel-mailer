<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Events;

use Eudeka\LaravelMailer\DTO\NormalizedEmailPayload;
use Eudeka\LaravelMailer\DTO\ProviderResponse;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class EmailSentViaProvider
{
    use Dispatchable;

    public function __construct(
        public string $providerName,
        public ProviderResponse $response,
        public NormalizedEmailPayload $payload,
        public int $attempts = 1,
    ) {}
}
