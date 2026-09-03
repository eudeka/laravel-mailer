<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

class FailoverTestMailable extends Mailable
{
    public function build(): self
    {
        return $this->subject('Failover Test')
            ->html('<p>Testing native Laravel failover transport</p>');
    }
}

it('delivers via the first provider when it is healthy', function () {
    config()->set('mail.mailers.resend.key', 're_test_key');
    config()->set('mail.mailers.brevo.key', 'brevo_test_key');
    config()->set('mail.mailers.failover', [
        'transport' => 'failover',
        'mailers' => ['resend', 'brevo'],
    ]);
    config()->set('mail.default', 'failover');
    config()->set('mail.from.address', 'noreply@myapp.com');
    config()->set('mail.from.name', 'My App');

    Http::fake([
        'https://api.resend.com/emails' => Http::response(['id' => 'resend_ok_001'], 200),
        'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo_should_not_be_called'], 201),
    ]);

    Mail::to('recipient@example.com')->send(new FailoverTestMailable);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.resend.com/emails');
    Http::assertNotSent(fn (Request $r) => $r->url() === 'https://api.brevo.com/v3/smtp/email');
});

it('fails over to the second provider when the first encounters a server error', function () {
    config()->set('mail.mailers.resend.key', 're_test_key');
    config()->set('mail.mailers.brevo.key', 'brevo_test_key');
    config()->set('mail.mailers.failover', [
        'transport' => 'failover',
        'mailers' => ['resend', 'brevo'],
    ]);
    config()->set('mail.default', 'failover');
    config()->set('mail.from.address', 'noreply@myapp.com');
    config()->set('mail.from.name', 'My App');

    Http::fake([
        'https://api.resend.com/emails' => Http::response(['message' => 'Internal Server Error'], 500),
        'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo_fallback_success'], 201),
    ]);

    Mail::to('recipient@example.com')->send(new FailoverTestMailable);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.resend.com/emails');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.brevo.com/v3/smtp/email');
});

it('skips unconfigured provider and sends through the next configured provider', function () {
    config()->set('mail.mailers.resend.key', null);
    config()->set('laravel-mailer.resend.key', null);
    config()->set('mail.mailers.brevo.key', 'brevo_test_key');
    config()->set('mail.mailers.failover', [
        'transport' => 'failover',
        'mailers' => ['resend', 'brevo'],
    ]);
    config()->set('mail.default', 'failover');
    config()->set('mail.from.address', 'noreply@myapp.com');
    config()->set('mail.from.name', 'My App');

    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo_auto_fallback'], 201),
    ]);

    Mail::to('recipient@example.com')->send(new FailoverTestMailable);

    Http::assertNotSent(fn (Request $r) => $r->url() === 'https://api.resend.com/emails');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.brevo.com/v3/smtp/email');
});

it('throws exception when all providers in failover fail', function () {
    config()->set('mail.mailers.resend.key', 're_test_key');
    config()->set('mail.mailers.brevo.key', 'brevo_test_key');
    config()->set('mail.mailers.failover', [
        'transport' => 'failover',
        'mailers' => ['resend', 'brevo'],
    ]);
    config()->set('mail.default', 'failover');
    config()->set('mail.from.address', 'noreply@myapp.com');
    config()->set('mail.from.name', 'My App');

    Http::fake([
        'https://api.resend.com/emails' => Http::response(['message' => 'Resend Down'], 500),
        'https://api.brevo.com/v3/smtp/email' => Http::response(['message' => 'Brevo Down'], 500),
    ]);

    expect(function () {
        Mail::to('recipient@example.com')->send(new FailoverTestMailable);
    })->toThrow(TransportException::class, 'All transports failed');
});
