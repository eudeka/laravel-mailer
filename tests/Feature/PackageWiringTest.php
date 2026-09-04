<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Tests\Feature;

use Eudeka\LaravelMailer\LaravelMailerServiceProvider;
use Eudeka\LaravelMailer\Transport\BrevoApiTransport;
use Eudeka\LaravelMailer\Transport\ResendApiTransport;
use Eudeka\LaravelMailer\Transport\Smtp2GoApiTransport;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Artisan;

it('registers individual drivers and auto-injects default mailers configuration', function () {
    expect(config('mail.mailers.resend.transport'))->toBe('resend')
        ->and(config('mail.mailers.brevo.transport'))->toBe('brevo')
        ->and(config('mail.mailers.smtp2go.transport'))->toBe('smtp2go');

    /** @var MailManager $mailManager */
    $mailManager = app('mail.manager');

    $resend = $mailManager->createSymfonyTransport(['transport' => 'resend']);
    expect($resend)->toBeInstanceOf(ResendApiTransport::class)
        ->and((string) $resend)->toBe('resend');

    $brevo = $mailManager->createSymfonyTransport(['transport' => 'brevo']);
    expect($brevo)->toBeInstanceOf(BrevoApiTransport::class)
        ->and((string) $brevo)->toBe('brevo');

    $smtp2go = $mailManager->createSymfonyTransport(['transport' => 'smtp2go']);
    expect($smtp2go)->toBeInstanceOf(Smtp2GoApiTransport::class)
        ->and((string) $smtp2go)->toBe('smtp2go');
});

it('registers artisan installer command', function () {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('eudeka:mailer-install');
});

it('automatically configures failover mailers when providers have API keys', function () {
    config()->set('mail.mailers.resend.key', 're_123');
    config()->set('mail.mailers.brevo.key', 'brevo_123');
    config()->set('mail.mailers.smtp2go.key', null);
    config()->set('mail.mailers.failover.mailers', ['smtp', 'log']);

    app(LaravelMailerServiceProvider::class, ['app' => app()])->boot();

    expect(config('mail.mailers.failover.transport'))->toBe('failover')
        ->and(config('mail.mailers.failover.mailers'))->toBe(['brevo', 'resend']);
});

it('respects custom MAIL_FAILOVER_MAILERS order', function () {
    config()->set('mail.mailers.resend.key', 're_123');
    config()->set('mail.mailers.brevo.key', 'brevo_123');
    config()->set('mail.mailers.smtp2go.key', 'smtp2go_123');
    config()->set('mail.mailers.failover.mailers', ['smtp2go', 'resend']);

    app(LaravelMailerServiceProvider::class, ['app' => app()])->boot();

    expect(config('mail.mailers.failover.mailers'))->toBe(['smtp2go', 'resend']);
});
