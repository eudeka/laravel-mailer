<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Providers;

use Eudeka\LaravelMailer\Contracts\EmailProviderInterface;
use Eudeka\LaravelMailer\DTO\NormalizedEmailPayload;
use Eudeka\LaravelMailer\DTO\ProviderResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

abstract class AbstractEmailProvider implements EmailProviderInterface
{
    public function __construct(
        protected readonly ?string $apiKey,
        protected readonly string $endpoint,
        protected readonly int $timeout = 10,
    ) {}

    public function hasCredentials(): bool
    {
        return $this->apiKey !== null && trim($this->apiKey) !== '';
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    /**
     * Send a normalized email payload to the vendor API.
     */
    abstract public function send(NormalizedEmailPayload $payload): ProviderResponse;

    /**
     * Send an HTTP POST request with common error handling.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     */
    protected function postJson(array $headers, array $body): ProviderResponse
    {
        try {
            /** @var Response $response */
            $response = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->connectTimeout(min(5, $this->timeout))
                ->post($this->endpoint, $body);

            return $this->handleHttpResponse($response);
        } catch (Throwable $e) {
            return ProviderResponse::failure(
                providerName: $this->name(),
                statusCode: 0,
                errorMessage: sprintf('Transport exception [%s]: %s', get_class($e), $e->getMessage()),
            );
        }
    }

    /**
     * Process the HTTP response into a normalized ProviderResponse.
     */
    abstract protected function handleHttpResponse(Response $response): ProviderResponse;
}
