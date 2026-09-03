<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer;

use Eudeka\LaravelMailer\Normalizer\PayloadNormalizer;
use Eudeka\LaravelMailer\Providers\BrevoProvider;
use Eudeka\LaravelMailer\Providers\ResendProvider;
use Eudeka\LaravelMailer\Providers\Smtp2goProvider;
use Eudeka\LaravelMailer\Transport\SingleProviderTransport;
use Illuminate\Contracts\Config\Repository;
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
        $this->mergeConfigFrom(__DIR__.'/../config/mailer.php', 'laravel-mailer');

        $this->app->singleton(PayloadNormalizer::class, fn (): PayloadNormalizer => new PayloadNormalizer);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerDefaultMailers();
        $this->configureFailoverMailer();
        $this->registerMailDrivers();
    }

    /**
     * Auto-inject default mailers into mail.mailers configuration if not already configured.
     */
    private function registerDefaultMailers(): void
    {
        /** @var Repository $configRepo */
        $configRepo = $this->app->make(Repository::class);

        $defaultMailers = [
            'resend' => [
                'transport' => 'resend',
                'key' => $configRepo->get('laravel-mailer.resend.key'),
                'endpoint' => $configRepo->get('laravel-mailer.resend.endpoint'),
                'timeout' => $configRepo->get('laravel-mailer.resend.timeout', 10),
            ],
            'brevo' => [
                'transport' => 'brevo',
                'key' => $configRepo->get('laravel-mailer.brevo.key'),
                'endpoint' => $configRepo->get('laravel-mailer.brevo.endpoint'),
                'timeout' => $configRepo->get('laravel-mailer.brevo.timeout', 10),
            ],
            'smtp2go' => [
                'transport' => 'smtp2go',
                'key' => $configRepo->get('laravel-mailer.smtp2go.key'),
                'endpoint' => $configRepo->get('laravel-mailer.smtp2go.endpoint'),
                'timeout' => $configRepo->get('laravel-mailer.smtp2go.timeout', 10),
            ],
        ];

        foreach ($defaultMailers as $name => $definition) {
            if (! $configRepo->has("mail.mailers.{$name}")) {
                $configRepo->set("mail.mailers.{$name}", $definition);
            }
        }
    }

    /**
     * Dynamically configure failover mailer with active providers having valid API keys.
     */
    private function configureFailoverMailer(): void
    {
        /** @var Repository $configRepo */
        $configRepo = $this->app->make(Repository::class);

        /** @var string|array<string>|null $configuredFailover */
        $configuredFailover = $configRepo->get('laravel-mailer.failover_mailers');
        $explicitOrder = false;

        if (is_string($configuredFailover) && trim($configuredFailover) !== '') {
            $priorityList = array_map('trim', explode(',', $configuredFailover));
            $explicitOrder = true;
        } elseif (is_array($configuredFailover) && ! empty($configuredFailover)) {
            /** @var array<string> $priorityList */
            $priorityList = $configuredFailover;
            $explicitOrder = true;
        } else {
            $priorityList = ['resend', 'brevo', 'smtp2go'];
        }

        $activeProviders = [];
        foreach ($priorityList as $driver) {
            $key = $configRepo->get("mail.mailers.{$driver}.key")
                ?? $configRepo->get("mail.mailers.{$driver}.api_key")
                ?? $configRepo->get("laravel-mailer.{$driver}.key");

            if (is_string($key) && trim($key) !== '') {
                $activeProviders[] = $driver;
            }
        }

        $currentFailover = $configRepo->get('mail.mailers.failover');
        $currentMailers = is_array($currentFailover) && isset($currentFailover['mailers']) && is_array($currentFailover['mailers'])
            ? $currentFailover['mailers']
            : [];

        $isDefaultLaravelFailover = $currentMailers === ['smtp', 'log'] || empty($currentMailers);

        if (! empty($activeProviders) && ($explicitOrder || $isDefaultLaravelFailover || ! $configRepo->has('mail.mailers.failover'))) {
            $configRepo->set('mail.mailers.failover', [
                'transport' => 'failover',
                'mailers' => $activeProviders,
            ]);
        }
    }

    /**
     * Register the individual mail transport drivers.
     */
    private function registerMailDrivers(): void
    {
        if ($this->app->bound('mail.manager')) {
            /** @var MailManager $mailManager */
            $mailManager = $this->app->make('mail.manager');

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
        /** @var Repository $configRepo */
        $configRepo = $this->app->make(Repository::class);

        $apiKey = isset($config['key']) && is_string($config['key'])
            ? $config['key']
            : (isset($config['api_key']) && is_string($config['api_key'])
                ? $config['api_key']
                : (is_string($configRepo->get("mail.mailers.{$driver}.key"))
                    ? (string) $configRepo->get("mail.mailers.{$driver}.key")
                    : (is_string($configRepo->get("mail.mailers.{$driver}.api_key"))
                        ? (string) $configRepo->get("mail.mailers.{$driver}.api_key")
                        : (is_string($configRepo->get("laravel-mailer.{$driver}.key"))
                            ? (string) $configRepo->get("laravel-mailer.{$driver}.key")
                            : null))));

        $endpoint = isset($config['endpoint']) && is_string($config['endpoint']) && $config['endpoint'] !== ''
            ? $config['endpoint']
            : (is_string($configRepo->get("mail.mailers.{$driver}.endpoint"))
                ? (string) $configRepo->get("mail.mailers.{$driver}.endpoint")
                : (is_string($configRepo->get("laravel-mailer.{$driver}.endpoint"))
                    ? (string) $configRepo->get("laravel-mailer.{$driver}.endpoint")
                    : null));

        $timeout = isset($config['timeout']) && is_numeric($config['timeout'])
            ? (int) $config['timeout']
            : (is_numeric($configRepo->get("mail.mailers.{$driver}.timeout"))
                ? (int) $configRepo->get("mail.mailers.{$driver}.timeout")
                : (is_numeric($configRepo->get("laravel-mailer.{$driver}.timeout"))
                    ? (int) $configRepo->get("laravel-mailer.{$driver}.timeout")
                    : 10));

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
            default => throw new InvalidArgumentException(sprintf('Unsupported email provider driver [%s].', $driver)),
        };

        return new SingleProviderTransport(
            provider: $provider,
            normalizer: $this->app->make(PayloadNormalizer::class),
        );
    }
}
