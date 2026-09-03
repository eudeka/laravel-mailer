<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Resilience;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

final readonly class CircuitBreaker
{
    public function __construct(
        private CacheRepository $cache,
        private bool $enabled = true,
        private int $defaultCooldownSeconds = 60,
        private string $keyPrefix = 'laravel_mailer_breaker:',
    ) {}

    /**
     * Determine if the given provider is available (not in cooldown).
     */
    public function isAvailable(string $provider): bool
    {
        if (! $this->enabled) {
            return true;
        }

        return ! $this->cache->has($this->cacheKey($provider));
    }

    /**
     * Put a provider into cooldown due to rate limiting or server failure.
     */
    public function trip(string $provider, ?int $cooldownSeconds = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $duration = $cooldownSeconds ?? $this->defaultCooldownSeconds;
        $expiryTimestamp = (int) now()->getTimestamp() + $duration;

        $this->cache->put(
            $this->cacheKey($provider),
            $expiryTimestamp,
            $duration,
        );
    }

    /**
     * Clear the cooldown for a provider upon successful operation.
     */
    public function reset(string $provider): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->cache->forget($this->cacheKey($provider));
    }

    /**
     * Get remaining cooldown seconds for a provider (0 if healthy/available).
     */
    public function getRemainingCooldown(string $provider): int
    {
        if (! $this->enabled) {
            return 0;
        }

        $expiry = $this->cache->get($this->cacheKey($provider));

        if (! is_int($expiry)) {
            return 0;
        }

        $remaining = $expiry - (int) now()->getTimestamp();

        return max(0, $remaining);
    }

    /**
     * Determine if the circuit breaker feature is globally enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    private function cacheKey(string $provider): string
    {
        return $this->keyPrefix.$provider;
    }
}
