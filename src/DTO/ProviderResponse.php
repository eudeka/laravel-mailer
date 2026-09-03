<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\DTO;

final readonly class ProviderResponse
{
    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public string $providerName,
        public bool $isSuccessful,
        public int $statusCode,
        public ?string $messageId = null,
        public array $rawResponse = [],
        public ?string $errorMessage = null,
    ) {}

    /**
     * Create a successful response instance.
     *
     * @param  array<string, mixed>  $rawResponse
     */
    public static function success(
        string $providerName,
        int $statusCode = 200,
        ?string $messageId = null,
        array $rawResponse = [],
    ): self {
        return new self(
            providerName: $providerName,
            isSuccessful: true,
            statusCode: $statusCode,
            messageId: $messageId,
            rawResponse: $rawResponse,
        );
    }

    /**
     * Create a failure response instance.
     *
     * @param  array<string, mixed>  $rawResponse
     */
    public static function failure(
        string $providerName,
        int $statusCode,
        string $errorMessage,
        array $rawResponse = [],
    ): self {
        return new self(
            providerName: $providerName,
            isSuccessful: false,
            statusCode: $statusCode,
            rawResponse: $rawResponse,
            errorMessage: $errorMessage,
        );
    }
}
