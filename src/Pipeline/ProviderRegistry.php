<?php

declare(strict_types=1);

namespace EmailProvider\EmailProvider\Pipeline;

use EmailProvider\EmailProvider\Contracts\EmailProviderInterface;
use EmailProvider\EmailProvider\Providers\BrevoProvider;
use EmailProvider\EmailProvider\Providers\ResendProvider;
use EmailProvider\EmailProvider\Providers\Smtp2goProvider;

final class ProviderRegistry
{
    /**
     * @var array<string, callable(): EmailProviderInterface>
     */
    private array $customResolvers = [];

    /**
     * @var array<string, EmailProviderInterface>|null
     */
    private ?array $resolvedProviders = null;

    /**
     * @param  array<string>  $priority
     * @param  array<string, array<string, mixed>>  $config
     */
    public function __construct(
        private readonly array $priority,
        private readonly array $config,
    ) {}

    /**
     * Register a custom provider resolver.
     *
     * @param  callable(): EmailProviderInterface  $resolver
     */
    public function registerCustomProvider(string $name, callable $resolver): void
    {
        $this->customResolvers[$name] = $resolver;
        $this->resolvedProviders = null;
    }

    /**
     * Get all configured providers in priority order.
     *
     * @return array<string, EmailProviderInterface>
     */
    public function all(): array
    {
        if ($this->resolvedProviders !== null) {
            return $this->resolvedProviders;
        }

        $providers = [];

        foreach ($this->priority as $name) {
            $provider = $this->resolveProvider($name);

            if ($provider !== null) {
                $providers[$name] = $provider;
            }
        }

        $this->resolvedProviders = $providers;

        return $this->resolvedProviders;
    }

    /**
     * Get active providers with valid credentials, in priority order (pruned of unconfigured nodes).
     *
     * @return array<string, EmailProviderInterface>
     */
    public function active(): array
    {
        return array_filter($this->all(), fn (EmailProviderInterface $p): bool => $p->hasCredentials());
    }

    /**
     * Get unconfigured providers that have been pruned from the execution sequence.
     *
     * @return array<string, EmailProviderInterface>
     */
    public function pruned(): array
    {
        return array_filter($this->all(), fn (EmailProviderInterface $p): bool => ! $p->hasCredentials());
    }

    /**
     * Resolve an individual provider instance by its name.
     */
    public function resolveProvider(string $name): ?EmailProviderInterface
    {
        if (isset($this->customResolvers[$name])) {
            return ($this->customResolvers[$name])();
        }

        $providerConfig = $this->config[$name] ?? [];
        $apiKey = isset($providerConfig['api_key']) && is_string($providerConfig['api_key'])
            ? $providerConfig['api_key']
            : null;
        $timeout = isset($providerConfig['timeout']) && is_numeric($providerConfig['timeout'])
            ? (int) $providerConfig['timeout']
            : 10;

        return match ($name) {
            'resend' => new ResendProvider(
                apiKey: $apiKey,
                endpoint: (string) ($providerConfig['endpoint'] ?? 'https://api.resend.com/emails'),
                timeout: $timeout,
            ),
            'brevo' => new BrevoProvider(
                apiKey: $apiKey,
                endpoint: (string) ($providerConfig['endpoint'] ?? 'https://api.brevo.com/v3/smtp/email'),
                timeout: $timeout,
            ),
            'smtp2go' => new Smtp2goProvider(
                apiKey: $apiKey,
                endpoint: (string) ($providerConfig['endpoint'] ?? 'https://api.smtp2go.com/v3/email/send'),
                timeout: $timeout,
            ),
            default => null,
        };
    }
}
