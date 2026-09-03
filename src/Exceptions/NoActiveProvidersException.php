<?php

declare(strict_types=1);

namespace EmailProvider\EmailProvider\Exceptions;

use RuntimeException;

final class NoActiveProvidersException extends RuntimeException
{
    public static function create(): self
    {
        return new self(
            'No email providers are available. Check that valid credentials are configured and that providers are not all in circuit breaker cooldown.',
        );
    }
}
