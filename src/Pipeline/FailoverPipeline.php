<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Pipeline;

use Eudeka\LaravelMailer\Contracts\EmailProviderInterface;
use Eudeka\LaravelMailer\DTO\NormalizedEmailPayload;
use Eudeka\LaravelMailer\DTO\ProviderResponse;
use Eudeka\LaravelMailer\Events\AllProvidersFailed;
use Eudeka\LaravelMailer\Events\EmailSentViaProvider;
use Eudeka\LaravelMailer\Events\ProviderAttemptFailed;
use Eudeka\LaravelMailer\Exceptions\AllProvidersFailedException;
use Eudeka\LaravelMailer\Exceptions\NoActiveProvidersException;
use Eudeka\LaravelMailer\Resilience\CircuitBreaker;
use Illuminate\Support\Facades\Log;

final readonly class FailoverPipeline
{
    public function __construct(
        private ProviderRegistry $registry,
        private CircuitBreaker $circuitBreaker,
    ) {}

    /**
     * Send an email payload through the sequential failover pipeline.
     *
     * @throws AllProvidersFailedException
     * @throws NoActiveProvidersException
     */
    public function send(NormalizedEmailPayload $payload): ProviderResponse
    {
        $activeProviders = $this->registry->active();

        if ($activeProviders === []) {
            throw NoActiveProvidersException::create();
        }

        // Filter out providers currently cooling down in the circuit breaker
        $candidates = array_filter(
            $activeProviders,
            fn (EmailProviderInterface $provider): bool => $this->circuitBreaker->isAvailable($provider->name()),
        );

        // If all configured providers are currently in cooldown, fallback to active providers as last resort
        if ($candidates === []) {
            $candidates = $activeProviders;
        }

        /** @var array<string, ProviderResponse> $failures */
        $failures = [];
        $attempts = 0;

        foreach ($candidates as $name => $provider) {
            $attempts++;
            $response = $provider->send($payload);

            if ($response->isSuccessful) {
                $this->circuitBreaker->reset($name);

                EmailSentViaProvider::dispatch(
                    $name,
                    $response,
                    $payload,
                    $attempts,
                );

                return $response;
            }

            // Provider failed: check if circuit breaker should trip (429 rate limit, 5xx server error, or connection failure 0)
            $shouldTrip = $response->statusCode === 429
                || $response->statusCode >= 500
                || $response->statusCode === 0;

            if ($shouldTrip) {
                $this->circuitBreaker->trip($name);
            }

            Log::warning(sprintf(
                'Email provider [%s] failed with status [%d]: %s. Failing over to next provider...',
                $name,
                $response->statusCode,
                $response->errorMessage ?? 'Unknown error',
            ));

            $failures[$name] = $response;

            ProviderAttemptFailed::dispatch(
                $name,
                $response,
                $payload,
                $shouldTrip,
            );
        }

        AllProvidersFailed::dispatch($payload, $failures);

        throw AllProvidersFailedException::fromFailures($failures);
    }
}
