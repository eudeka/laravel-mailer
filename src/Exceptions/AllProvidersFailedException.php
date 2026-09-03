<?php

declare(strict_types=1);

namespace EmailProvider\EmailProvider\Exceptions;

use EmailProvider\EmailProvider\DTO\ProviderResponse;
use RuntimeException;

final class AllProvidersFailedException extends RuntimeException
{
    /**
     * @param  array<string, ProviderResponse>  $failures
     */
    public function __construct(
        public readonly array $failures,
        string $message = 'All configured email providers failed sequentially.',
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, ProviderResponse>  $failures
     */
    public static function fromFailures(array $failures): self
    {
        $details = [];

        foreach ($failures as $provider => $response) {
            $details[] = sprintf('%s (Status %d: %s)', $provider, $response->statusCode, $response->errorMessage ?? 'Unknown error');
        }

        $message = sprintf(
            'All configured email providers failed sequentially: [%s]',
            implode('; ', $details),
        );

        return new self($failures, $message);
    }
}
