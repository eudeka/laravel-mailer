<?php

declare(strict_types=1);

namespace EmailProvider\EmailProvider\Events;

use EmailProvider\EmailProvider\DTO\NormalizedEmailPayload;
use EmailProvider\EmailProvider\DTO\ProviderResponse;
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
