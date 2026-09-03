<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer;

use Eudeka\LaravelMailer\Console\Commands\StatusCommand;
use Eudeka\LaravelMailer\Console\Commands\TestCommand;
use Eudeka\LaravelMailer\Normalizer\PayloadNormalizer;
use Eudeka\LaravelMailer\Pipeline\FailoverPipeline;
use Eudeka\LaravelMailer\Pipeline\ProviderRegistry;
use Eudeka\LaravelMailer\Providers\BrevoProvider;
use Eudeka\LaravelMailer\Providers\ResendProvider;
use Eudeka\LaravelMailer\Providers\Smtp2goProvider;
use Eudeka\LaravelMailer\Resilience\CircuitBreaker;
use Eudeka\LaravelMailer\Transport\MultiVendorTransport;
use Eudeka\LaravelMailer\Transport\SingleProviderTransport;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class LaravelMailerServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mailer.php', 'mailer');

        $this->app->singleton(PayloadNormalizer::class, fn (): PayloadNormalizer => new PayloadNormalizer);

        $this->app->singleton(CircuitBreaker::class, function (Application $app): CircuitBreaker {
            /** @var Repository $configRepo */
            $configRepo = $app->make(Repository::class);
            /** @var array<string, mixed> $config */
            $config = (array) $configRepo->get('mailer.circuit_breaker', []);
            $store = isset($config['cache_store']) && is_string($config['cache_store']) ? $config['cache_store'] : null;

            /** @var Factory $cacheFactory */
            $cacheFactory = $app->make(Factory::class);
            /** @var CacheRepository $cache */
            $cache = $cacheFactory->store($store);

            $cooldown = isset($config['cooldown_seconds']) && is_numeric($config['cooldown_seconds'])
                ? (int) $config['cooldown_seconds']
                : 60;
            $prefix = isset($config['cache_prefix']) && is_string($config['cache_prefix'])
                ? $config['cache_prefix']
                : 'laravel_mailer_breaker:';

            return new CircuitBreaker(
                cache: $cache,
                enabled: (bool) ($config['enabled'] ?? true),
                defaultCooldownSeconds: $cooldown,
                keyPrefix: $prefix,
            );
        });

        $this->app->singleton(ProviderRegistry::class, function (Application $app): ProviderRegistry {
            /** @var Repository $configRepo */
            $configRepo = $app->make(Repository::class);
            /** @var array<string> $priority */
            $priority = (array) $configRepo->get('mailer.priority', []);

            /** @var array<string, array<string, mixed>> $providers */
            $providers = (array) $configRepo->get('mailer.providers', []);

            return new ProviderRegistry(
                priority: $priority,
                config: $providers,
            );
        });

        $this->app->singleton(FailoverPipeline::class, function (Application $app): FailoverPipeline {
            return new FailoverPipeline(
                registry: $app->make(ProviderRegistry::class),
                circuitBreaker: $app->make(CircuitBreaker::class),
            );
        });

        $this->app->singleton(MultiVendorTransport::class, function (Application $app): MultiVendorTransport {
            return new MultiVendorTransport(
                pipeline: $app->make(FailoverPipeline::class),
                normalizer: $app->make(PayloadNormalizer::class),
            );
        });

        $this->app->singleton(LaravelMailer::class, function (Application $app): LaravelMailer {
            return new LaravelMailer(
                registry: $app->make(ProviderRegistry::class),
                pipeline: $app->make(FailoverPipeline::class),
                circuitBreaker: $app->make(CircuitBreaker::class),
                normalizer: $app->make(PayloadNormalizer::class),
            );
        });

        $this->app->alias(LaravelMailer::class, 'laravel-mailer');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerDefaultMailers();
        $this->registerMailDrivers();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/mailer.php' => config_path('mailer.php'),
        ], ['mailer', 'mailer-config']);

        $this->commands([
            StatusCommand::class,
            TestCommand::class,
        ]);
    }

    /**
     * Auto-inject default mailers into mail.mailers configuration if not already configured.
     */
    private function registerDefaultMailers(): void
    {
        /** @var Repository $configRepo */
        $configRepo = $this->app->make(Repository::class);

        $defaultMailers = [
            'multi-vendor' => ['transport' => 'multi-vendor'],
            'mailer' => ['transport' => 'mailer'],
            'resend' => ['transport' => 'resend'],
            'brevo' => ['transport' => 'brevo'],
            'smtp2go' => ['transport' => 'smtp2go'],
        ];

        foreach ($defaultMailers as $name => $definition) {
            if (! $configRepo->has("mail.mailers.{$name}")) {
                $configRepo->set("mail.mailers.{$name}", $definition);
            }
        }
    }

    /**
     * Register the multi-vendor and individual mail transport drivers.
     */
    private function registerMailDrivers(): void
    {
        if ($this->app->bound('mail.manager')) {
            /** @var MailManager $mailManager */
            $mailManager = $this->app->make('mail.manager');

            $mailManager->extend('multi-vendor', function (): MultiVendorTransport {
                return $this->app->make(MultiVendorTransport::class);
            });

            $mailManager->extend('mailer', function (): MultiVendorTransport {
                return $this->app->make(MultiVendorTransport::class);
            });

            foreach (['resend', 'brevo', 'smtp2go'] as $driver) {
                $mailManager->extend($driver, function (array $config = []) use ($driver): SingleProviderTransport {
                    /** @var array<string, mixed> $typedConfig */
                    $typedConfig = $config;

                    return $this->createSingleProviderTransport($driver, $typedConfig);
                });
            }
        }
    }

    /**
     * Resolve a SingleProviderTransport for the given driver.
     *
     * @param  array<string, mixed>  $config
     */
    private function createSingleProviderTransport(string $driver, array $config = []): SingleProviderTransport
    {
        /** @var ProviderRegistry $registry */
        $registry = $this->app->make(ProviderRegistry::class);

        /** @var Repository $configRepo */
        $configRepo = $this->app->make(Repository::class);

        if (isset($config['api_key']) || isset($config['endpoint']) || isset($config['timeout'])) {
            $fallbackApiKey = $configRepo->get("mailer.providers.{$driver}.api_key");
            $apiKey = isset($config['api_key']) && is_string($config['api_key'])
                ? $config['api_key']
                : (is_string($fallbackApiKey) ? $fallbackApiKey : '');

            $fallbackEndpoint = $configRepo->get("mailer.providers.{$driver}.endpoint");
            $endpoint = isset($config['endpoint']) && is_string($config['endpoint']) && $config['endpoint'] !== ''
                ? $config['endpoint']
                : (is_string($fallbackEndpoint) && $fallbackEndpoint !== '' ? $fallbackEndpoint : null);

            $fallbackTimeout = $configRepo->get("mailer.providers.{$driver}.timeout");
            $timeout = isset($config['timeout']) && is_numeric($config['timeout'])
                ? (int) $config['timeout']
                : (is_numeric($fallbackTimeout) ? (int) $fallbackTimeout : 10);

            $provider = match ($driver) {
                'resend' => new ResendProvider(
                    apiKey: $apiKey !== '' ? $apiKey : null,
                    endpoint: $endpoint ?? 'https://api.resend.com/emails',
                    timeout: $timeout,
                ),
                'brevo' => new BrevoProvider(
                    apiKey: $apiKey !== '' ? $apiKey : null,
                    endpoint: $endpoint ?? 'https://api.brevo.com/v3/smtp/email',
                    timeout: $timeout,
                ),
                'smtp2go' => new Smtp2goProvider(
                    apiKey: $apiKey !== '' ? $apiKey : null,
                    endpoint: $endpoint ?? 'https://api.smtp2go.com/v3/email/send',
                    timeout: $timeout,
                ),
                default => $registry->resolveProvider($driver),
            };
        } else {
            $provider = $registry->resolveProvider($driver);
        }

        if ($provider === null) {
            throw new InvalidArgumentException(sprintf('Unsupported email provider driver [%s].', $driver));
        }

        return new SingleProviderTransport(
            provider: $provider,
            normalizer: $this->app->make(PayloadNormalizer::class),
        );
    }
}
