<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer;

use Eudeka\LaravelMailer\Console\Commands\StatusCommand;
use Eudeka\LaravelMailer\Console\Commands\TestCommand;
use Eudeka\LaravelMailer\Normalizer\PayloadNormalizer;
use Eudeka\LaravelMailer\Pipeline\FailoverPipeline;
use Eudeka\LaravelMailer\Pipeline\ProviderRegistry;
use Eudeka\LaravelMailer\Resilience\CircuitBreaker;
use Eudeka\LaravelMailer\Transport\MultiVendorTransport;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;

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

            return new CircuitBreaker(
                cache: $cache,
                enabled: (bool) ($config['enabled'] ?? true),
                defaultCooldownSeconds: (int) ($config['cooldown_seconds'] ?? 60),
                keyPrefix: (string) ($config['cache_prefix'] ?? 'laravel_mailer_breaker:'),
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
        $this->registerMailDriver();

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
     * Register the custom multi-vendor mail transport driver.
     */
    private function registerMailDriver(): void
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
        }
    }
}
