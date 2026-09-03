<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer;

use Eudeka\LaravelMailer\Contracts\EmailProviderInterface;
use Eudeka\LaravelMailer\DTO\NormalizedEmailPayload;
use Eudeka\LaravelMailer\DTO\ProviderResponse;
use Eudeka\LaravelMailer\Normalizer\PayloadNormalizer;
use Eudeka\LaravelMailer\Pipeline\FailoverPipeline;
use Eudeka\LaravelMailer\Pipeline\ProviderRegistry;
use Eudeka\LaravelMailer\Resilience\CircuitBreaker;

final readonly class LaravelMailer
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
