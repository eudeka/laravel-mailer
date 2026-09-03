<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Tests\Feature;

use Eudeka\LaravelMailer\LaravelMailerServiceProvider;
use Eudeka\LaravelMailer\Normalizer\PayloadNormalizer;
use Eudeka\LaravelMailer\Transport\SingleProviderTransport;
use Illuminate\Mail\MailManager;

it('binds PayloadNormalizer in container', function () {
    expect(app(PayloadNormalizer::class))->toBeInstanceOf(PayloadNormalizer::class)
        ->and(app(PayloadNormalizer::class))->toBe(app(PayloadNormalizer::class));
});

it('registers individual drivers and auto-injects default mailers configuration', function () {
    expect(config('mail.mailers.resend.transport'))->toBe('resend')
        ->and(config('mail.mailers.brevo.transport'))->toBe('brevo')
        ->and(config('mail.mailers.smtp2go.transport'))->toBe('smtp2go');

    /** @var MailManager $mailManager */
    $mailManager = app('mail.manager');

    foreach (['resend', 'brevo', 'smtp2go'] as $driver) {
        $transport = $mailManager->createSymfonyTransport(['transport' => $driver]);
        expect($transport)->toBeInstanceOf(SingleProviderTransport::class)
            ->and((string) $transport)->toBe($driver);
    }
});

it('automatically configures failover mailers when providers have API keys', function () {
    config()->set('mail.mailers.resend.key', 're_123');
    config()->set('mail.mailers.brevo.key', 'brevo_123');
    config()->set('mail.mailers.smtp2go.key', null);
    config()->set('laravel-mailer.smtp2go.key', null);
    config()->set('mail.mailers.failover.mailers', ['smtp', 'log']);

    // Re-run service provider boot to verify dynamic failover configuration
    app(LaravelMailerServiceProvider::class, ['app' => app()])->boot();

    expect(config('mail.mailers.failover.transport'))->toBe('failover')
        ->and(config('mail.mailers.failover.mailers'))->toBe(['resend', 'brevo']);
});

it('respects custom FAILOVER_MAILERS order', function () {
    config()->set('mail.mailers.resend.key', 're_123');
    config()->set('mail.mailers.brevo.key', 'brevo_123');
    config()->set('mail.mailers.smtp2go.key', 'smtp2go_123');
    config()->set('laravel-mailer.failover_mailers', 'smtp2go,resend');

    app(LaravelMailerServiceProvider::class, ['app' => app()])->boot();

    expect(config('mail.mailers.failover.mailers'))->toBe(['smtp2go', 'resend']);
});
