<?php

declare(strict_types=1);

use EmailProvider\EmailProvider\Contracts\EmailProviderInterface;
use EmailProvider\EmailProvider\DTO\NormalizedEmailPayload;
use EmailProvider\EmailProvider\DTO\ProviderResponse;
use EmailProvider\EmailProvider\EmailProvider;
use EmailProvider\EmailProvider\Normalizer\PayloadNormalizer;
use EmailProvider\EmailProvider\Pipeline\FailoverPipeline;
use EmailProvider\EmailProvider\Pipeline\ProviderRegistry;
use EmailProvider\EmailProvider\Resilience\CircuitBreaker;
use EmailProvider\EmailProvider\Transport\MultiVendorTransport;
use Illuminate\Mail\MailManager;

it('resolves all package singletons from the container', function () {
    expect(app(EmailProvider::class))->toBeInstanceOf(EmailProvider::class)
        ->and(app(CircuitBreaker::class))->toBeInstanceOf(CircuitBreaker::class)
        ->and(app(ProviderRegistry::class))->toBeInstanceOf(ProviderRegistry::class)
        ->and(app(FailoverPipeline::class))->toBeInstanceOf(FailoverPipeline::class)
        ->and(app(PayloadNormalizer::class))->toBeInstanceOf(PayloadNormalizer::class)
        ->and(app(MultiVendorTransport::class))->toBeInstanceOf(MultiVendorTransport::class);

    expect(app(EmailProvider::class))->toBe(app(EmailProvider::class));
});

it('merges the package default configuration', function () {
    expect(config('email-provider.priority'))->toBe(['resend', 'brevo', 'smtp2go'])
        ->and(config('email-provider.circuit_breaker.enabled'))->toBeTrue()
        ->and(config('email-provider.circuit_breaker.cooldown_seconds'))->toBe(60);
});

it('registers the multi-vendor transport driver with Laravel MailManager', function () {
    /** @var MailManager $mailManager */
    $mailManager = app('mail.manager');
    $transport = $mailManager->createSymfonyTransport(['transport' => 'multi-vendor']);

    expect($transport)->toBeInstanceOf(MultiVendorTransport::class);
});

it('allows extending with custom providers via EmailProvider facade or manager', function () {
    /** @var EmailProvider $manager */
    $manager = app(EmailProvider::class);

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
