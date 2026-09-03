<?php

declare(strict_types=1);

use Eudeka\LaravelMailer\Contracts\EmailProviderInterface;
use Eudeka\LaravelMailer\DTO\NormalizedEmailPayload;
use Eudeka\LaravelMailer\DTO\ProviderResponse;
use Eudeka\LaravelMailer\Facades\LaravelMailer as LaravelMailerFacade;
use Eudeka\LaravelMailer\LaravelMailer;
use Eudeka\LaravelMailer\Normalizer\PayloadNormalizer;
use Eudeka\LaravelMailer\Pipeline\FailoverPipeline;
use Eudeka\LaravelMailer\Pipeline\ProviderRegistry;
use Eudeka\LaravelMailer\Resilience\CircuitBreaker;
use Eudeka\LaravelMailer\Transport\MultiVendorTransport;
use Eudeka\LaravelMailer\Transport\SingleProviderTransport;
use Illuminate\Mail\MailManager;

it('resolves all package singletons from the container', function () {
    expect(app(LaravelMailer::class))->toBeInstanceOf(LaravelMailer::class)
        ->and(app('laravel-mailer'))->toBeInstanceOf(LaravelMailer::class)
        ->and(app(CircuitBreaker::class))->toBeInstanceOf(CircuitBreaker::class)
        ->and(app(ProviderRegistry::class))->toBeInstanceOf(ProviderRegistry::class)
        ->and(app(FailoverPipeline::class))->toBeInstanceOf(FailoverPipeline::class)
        ->and(app(PayloadNormalizer::class))->toBeInstanceOf(PayloadNormalizer::class)
        ->and(app(MultiVendorTransport::class))->toBeInstanceOf(MultiVendorTransport::class);

    expect(app(LaravelMailer::class))->toBe(app(LaravelMailer::class));
});

it('resolves the LaravelMailer facade', function () {
    expect(LaravelMailerFacade::getFacadeRoot())->toBeInstanceOf(LaravelMailer::class)
        ->and(LaravelMailerFacade::registry())->toBeInstanceOf(ProviderRegistry::class)
        ->and(LaravelMailerFacade::pipeline())->toBeInstanceOf(FailoverPipeline::class)
        ->and(LaravelMailerFacade::circuitBreaker())->toBeInstanceOf(CircuitBreaker::class);
});

it('merges the package default configuration', function () {
    expect(config('mailer.priority'))->toBe(['resend', 'brevo', 'smtp2go'])
        ->and(config('mailer.circuit_breaker.enabled'))->toBeTrue()
        ->and(config('mailer.circuit_breaker.cooldown_seconds'))->toBe(60);
});

it('registers the multi-vendor transport driver with Laravel MailManager', function () {
    /** @var MailManager $mailManager */
    $mailManager = app('mail.manager');
    $transport = $mailManager->createSymfonyTransport(['transport' => 'multi-vendor']);

    expect($transport)->toBeInstanceOf(MultiVendorTransport::class);

    $aliasTransport = $mailManager->createSymfonyTransport(['transport' => 'mailer']);
    expect($aliasTransport)->toBeInstanceOf(MultiVendorTransport::class);
});

it('registers individual drivers and auto-injects default mailers configuration', function () {
    expect(config('mail.mailers.multi-vendor'))->toBe(['transport' => 'multi-vendor'])
        ->and(config('mail.mailers.resend'))->toBe(['transport' => 'resend'])
        ->and(config('mail.mailers.brevo'))->toBe(['transport' => 'brevo'])
        ->and(config('mail.mailers.smtp2go'))->toBe(['transport' => 'smtp2go']);

    /** @var MailManager $mailManager */
    $mailManager = app('mail.manager');

    foreach (['resend', 'brevo', 'smtp2go'] as $driver) {
        $transport = $mailManager->createSymfonyTransport(['transport' => $driver]);
        expect($transport)->toBeInstanceOf(SingleProviderTransport::class)
            ->and((string) $transport)->toBe($driver);
    }
});

it('allows extending with custom providers via LaravelMailer facade or manager', function () {
    /** @var LaravelMailer $manager */
    $manager = app(LaravelMailer::class);

    $manager->extend('custom_gateway', fn () => new class implements EmailProviderInterface
    {
        public function name(): string
        {
            return 'custom_gateway';
        }

        public function hasCredentials(): bool
        {
            return true;
        }

        public function send(NormalizedEmailPayload $payload): ProviderResponse
        {
            return ProviderResponse::success('custom_gateway', 200, 'mock_id');
        }
    });

    $resolved = $manager->registry()->resolveProvider('custom_gateway');
    expect($resolved)->not->toBeNull()
        ->and($resolved?->name())->toBe('custom_gateway');
});

it('publishes the mailer configuration file via mailer-config tag', function () {
    $target = config_path('mailer.php');

    if (file_exists($target)) {
        unlink($target);
    }

    $this->artisan('vendor:publish', ['--tag' => 'mailer-config'])
        ->assertSuccessful();

    expect(file_exists($target))->toBeTrue();

    if (file_exists($target)) {
        unlink($target);
    }
});
