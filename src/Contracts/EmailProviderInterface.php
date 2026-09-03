<?php

declare(strict_types=1);

namespace EmailProvider\EmailProvider\Contracts;

use EmailProvider\EmailProvider\DTO\NormalizedEmailPayload;
use EmailProvider\EmailProvider\DTO\ProviderResponse;

interface EmailProviderInterface
{
    /**
     * Get the unique name identifier of the provider.
     */
    public function name(): string;

    /**
     * Determine if the provider has valid configured credentials.
     */
    public function hasCredentials(): bool;

    /**
     * Send the normalized email payload to the vendor REST API.
     */
    public function send(NormalizedEmailPayload $payload): ProviderResponse;
}
