<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Events;

use Eudeka\LaravelMailer\DTO\NormalizedEmailPayload;
use Eudeka\LaravelMailer\DTO\ProviderResponse;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class AllProvidersFailed
{
    use Dispatchable;

    /**
     * @param  array<string, ProviderResponse>  $failures
     */
    public function __construct(
        public NormalizedEmailPayload $payload,
        public array $failures,
    ) {}
}
