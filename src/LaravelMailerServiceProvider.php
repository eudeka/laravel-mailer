<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer;

use Eudeka\LaravelMailer\Commands\MailerInstallCommand;
use Eudeka\LaravelMailer\Transport\BrevoApiTransport;
use Eudeka\LaravelMailer\Transport\ResendApiTransport;
use Eudeka\LaravelMailer\Transport\Smtp2GoApiTransport;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;

class LaravelMailerServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mailers.php', 'mail.mailers');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mailers.php' => config_path('mailers.php'),
            ], 'mailer-config');

            $this->commands([
                MailerInstallCommand::class,
            ]);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureFailoverMailer();
        $this->registerMailDrivers();
    }

    /**
     * Dynamically configure failover mailer with active providers having valid API keys.
     */
    private function configureFailoverMailer(): void
    {
        /** @var Repository $configRepo */
        $configRepo = $this->app->make(Repository::class);

        /** @var mixed $configuredFailover */
        $configuredFailover = $configRepo->get('mail.mailers.failover.mailers');
        $isDefaultLaravelFailover = $configuredFailover === ['smtp', 'log'] || empty($configuredFailover);

        $explicitOrder = false;
        $priorityList = ['brevo', 'resend', 'smtp2go'];

        if (is_string($configuredFailover) && trim($configuredFailover) !== '') {
            $priorityList = array_values(array_filter(array_map('trim', explode(',', $configuredFailover))));
            $explicitOrder = true;
        } elseif (is_array($configuredFailover) && ! $isDefaultLaravelFailover) {
            /** @var array<string> $priorityList */
            $priorityList = array_values($configuredFailover);
            $explicitOrder = true;
        }

        $activeProviders = [];
        foreach ($priorityList as $driver) {
            $key = $configRepo->get("mail.mailers.{$driver}.key")
                ?? $configRepo->get("mail.mailers.{$driver}.api_key");

            if (is_string($key) && trim($key) !== '') {
                $activeProviders[] = $driver;
            }
        }

        if ($activeProviders !== [] && ($isDefaultLaravelFailover || ! $configRepo->has('mail.mailers.failover'))) {
            $configRepo->set('mail.mailers.failover', [
                'transport' => 'failover',
                'mailers' => $activeProviders,
            ]);
        } elseif ($activeProviders !== [] && $explicitOrder) {
            $configRepo->set('mail.mailers.failover.mailers', $activeProviders);
        }
    }

    /**
     * Register the individual mail transport drivers with Laravel's MailManager.
     */
    private function registerMailDrivers(): void
    {
        if (! $this->app->bound('mail.manager')) {
            return;
        }

        /** @var MailManager $mailManager */
        $mailManager = $this->app->make('mail.manager');

        $mailManager->extend('brevo', function (array $config = []): BrevoApiTransport {
            /** @var array<string, mixed> $typedConfig */
            $typedConfig = $config;

            return $this->createBrevoTransport($typedConfig);
        });

        $mailManager->extend('resend', function (array $config = []): ResendApiTransport {
            /** @var array<string, mixed> $typedConfig */
            $typedConfig = $config;

            return $this->createResendTransport($typedConfig);
        });

        $mailManager->extend('smtp2go', function (array $config = []): Smtp2GoApiTransport {
            /** @var array<string, mixed> $typedConfig */
            $typedConfig = $config;

            return $this->createSmtp2GoTransport($typedConfig);
        });
    }

    /**
     * Resolve a BrevoApiTransport instance.
     *
     * @param  array<string, mixed>  $config
     */
    private function createBrevoTransport(array $config = []): BrevoApiTransport
    {
        /** @var Repository $configRepo */
        $configRepo = $this->app->make(Repository::class);

        $apiKey = isset($config['key']) && is_string($config['key'])
            ? $config['key']
            : (isset($config['api_key']) && is_string($config['api_key'])
                ? $config['api_key']
                : (is_string($configRepo->get('mail.mailers.brevo.key'))
                    ? (string) $configRepo->get('mail.mailers.brevo.key')
                    : (is_string($configRepo->get('mail.mailers.brevo.api_key'))
                        ? (string) $configRepo->get('mail.mailers.brevo.api_key')
                        : null)));

        $endpoint = isset($config['endpoint']) && is_string($config['endpoint']) && $config['endpoint'] !== ''
            ? $config['endpoint']
            : (is_string($configRepo->get('mail.mailers.brevo.endpoint'))
                ? (string) $configRepo->get('mail.mailers.brevo.endpoint')
                : 'https://api.brevo.com/v3/smtp/email');

        $timeout = isset($config['timeout']) && is_numeric($config['timeout'])
            ? (int) $config['timeout']
            : (is_numeric($configRepo->get('mail.mailers.brevo.timeout'))
                ? (int) $configRepo->get('mail.mailers.brevo.timeout')
                : 10);

        return new BrevoApiTransport(
            apiKey: $apiKey !== '' ? $apiKey : null,
            endpoint: $endpoint,
            timeout: $timeout,
        );
    }

    /**
     * Resolve a ResendApiTransport instance.
     *
     * @param  array<string, mixed>  $config
     */
    private function createResendTransport(array $config = []): ResendApiTransport
    {
        /** @var Repository $configRepo */
        $configRepo = $this->app->make(Repository::class);

        $apiKey = isset($config['key']) && is_string($config['key'])
            ? $config['key']
            : (isset($config['api_key']) && is_string($config['api_key'])
                ? $config['api_key']
                : (is_string($configRepo->get('mail.mailers.resend.key'))
                    ? (string) $configRepo->get('mail.mailers.resend.key')
                    : (is_string($configRepo->get('mail.mailers.resend.api_key'))
                        ? (string) $configRepo->get('mail.mailers.resend.api_key')
                        : null)));

        $endpoint = isset($config['endpoint']) && is_string($config['endpoint']) && $config['endpoint'] !== ''
            ? $config['endpoint']
            : (is_string($configRepo->get('mail.mailers.resend.endpoint'))
                ? (string) $configRepo->get('mail.mailers.resend.endpoint')
                : 'https://api.resend.com/emails');

        $timeout = isset($config['timeout']) && is_numeric($config['timeout'])
            ? (int) $config['timeout']
            : (is_numeric($configRepo->get('mail.mailers.resend.timeout'))
                ? (int) $configRepo->get('mail.mailers.resend.timeout')
                : 10);

        return new ResendApiTransport(
            apiKey: $apiKey !== '' ? $apiKey : null,
            endpoint: $endpoint,
            timeout: $timeout,
        );
    }

    /**
     * Resolve a Smtp2GoApiTransport instance.
     *
     * @param  array<string, mixed>  $config
     */
    private function createSmtp2GoTransport(array $config = []): Smtp2GoApiTransport
    {
        /** @var Repository $configRepo */
        $configRepo = $this->app->make(Repository::class);

        $apiKey = isset($config['key']) && is_string($config['key'])
            ? $config['key']
            : (isset($config['api_key']) && is_string($config['api_key'])
                ? $config['api_key']
                : (is_string($configRepo->get('mail.mailers.smtp2go.key'))
                    ? (string) $configRepo->get('mail.mailers.smtp2go.key')
                    : (is_string($configRepo->get('mail.mailers.smtp2go.api_key'))
                        ? (string) $configRepo->get('mail.mailers.smtp2go.api_key')
                        : null)));

        $endpoint = isset($config['endpoint']) && is_string($config['endpoint']) && $config['endpoint'] !== ''
            ? $config['endpoint']
            : (is_string($configRepo->get('mail.mailers.smtp2go.endpoint'))
                ? (string) $configRepo->get('mail.mailers.smtp2go.endpoint')
                : 'https://api.smtp2go.com/v3/email/send');

        $timeout = isset($config['timeout']) && is_numeric($config['timeout'])
            ? (int) $config['timeout']
            : (is_numeric($configRepo->get('mail.mailers.smtp2go.timeout'))
                ? (int) $configRepo->get('mail.mailers.smtp2go.timeout')
                : 10);

        return new Smtp2GoApiTransport(
            apiKey: $apiKey !== '' ? $apiKey : null,
            endpoint: $endpoint,
            timeout: $timeout,
        );
    }
}
