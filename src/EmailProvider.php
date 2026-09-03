<?php

declare(strict_types=1);

namespace EmailProvider\EmailProvider;

use EmailProvider\EmailProvider\Contracts\EmailProviderInterface;
use EmailProvider\EmailProvider\DTO\NormalizedEmailPayload;
use EmailProvider\EmailProvider\DTO\ProviderResponse;
use EmailProvider\EmailProvider\Normalizer\PayloadNormalizer;
use EmailProvider\EmailProvider\Pipeline\FailoverPipeline;
use EmailProvider\EmailProvider\Pipeline\ProviderRegistry;
use EmailProvider\EmailProvider\Resilience\CircuitBreaker;

final readonly class EmailProvider
{
    public function __construct(
        private ProviderRegistry $registry,
        private FailoverPipeline $pipeline,
        private CircuitBreaker $circuitBreaker,
        private PayloadNormalizer $normalizer,
    ) {}

    /**
     * Send a normalized email payload directly through the failover pipeline.
     */
    public function send(NormalizedEmailPayload $payload): ProviderResponse
    {
        return $this->pipeline->send($payload);
    }

    /**
     * Register a custom email provider resolver.
     *
     * @param  callable(): EmailProviderInterface  $resolver
     */
    public function extend(string $name, callable $resolver): void
    {
        $this->registry->registerCustomProvider($name, $resolver);
    }

    public function pipeline(): FailoverPipeline
    {
        return $this->pipeline;
    }

    public function registry(): ProviderRegistry
    {
        return $this->registry;
    }

    public function circuitBreaker(): CircuitBreaker
    {
        return $this->circuitBreaker;
    }

    public function normalizer(): PayloadNormalizer
    {
        return $this->normalizer;
    }

    /**
     * Get a comprehensive status summary of all configured providers.
     *
     * @return array<int, array{name: string, configured: bool, active: bool, cooldown: int, healthy: bool}>
     */
    public function status(): array
    {
        $all = $this->registry->all();
        $statuses = [];

        foreach ($all as $name => $provider) {
            $configured = $provider->hasCredentials();
            $cooldown = $this->circuitBreaker->getRemainingCooldown($name);
            $healthy = $this->circuitBreaker->isAvailable($name);

            $statuses[] = [
                'name' => $name,
                'configured' => $configured,
                'active' => $configured && $healthy,
                'cooldown' => $cooldown,
                'healthy' => $healthy,
            ];
        }

        return $statuses;
    }
}
