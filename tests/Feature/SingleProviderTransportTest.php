<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

class StandardTestMailable extends Mailable
{
    public function build(): self
    {
        return $this->subject('Standard Laravel Mail')
            ->html('<p>Hello from single driver!</p>')
            ->withSymfonyMessage(function ($email) {
                $email->getHeaders()->addTextHeader('X-Tag', 'onboarding');
                $email->getHeaders()->addTextHeader('X-Metadata-user_id', '99');
            });
    }
}

it('sends email using resend driver directly via Mail facade', function () {
    config()->set('mail.mailers.resend.key', 're_direct_test_key');
    config()->set('mail.default', 'resend');
    config()->set('mail.from.address', 'noreply@myapp.com');
    config()->set('mail.from.name', 'My App');

    Http::fake([
        'https://api.resend.com/emails' => Http::response(['id' => 'resend_direct_001'], 200),
    ]);

    Mail::to('user@example.com')->send(new StandardTestMailable);

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return $request->url() === 'https://api.resend.com/emails'
            && $request->header('Authorization')[0] === 'Bearer re_direct_test_key'
            && $data['from'] === 'My App <noreply@myapp.com>'
            && $data['to'] === ['user@example.com']
            && $data['subject'] === 'Standard Laravel Mail'
            && $data['tags'][0] === ['name' => 'tag', 'value' => 'onboarding']
            && $data['tags'][1] === ['name' => 'user_id', 'value' => '99'];
    });
});

it('sends email using brevo driver directly via Mail facade', function () {
    config()->set('mail.mailers.brevo.key', 'brevo_direct_test_key');
    config()->set('mail.default', 'brevo');
    config()->set('mail.from.address', 'noreply@myapp.com');
    config()->set('mail.from.name', 'My App');

    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<brevo_direct_001>'], 201),
    ]);

    Mail::to('user@example.com')->send(new StandardTestMailable);

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return $request->url() === 'https://api.brevo.com/v3/smtp/email'
            && $request->header('api-key')[0] === 'brevo_direct_test_key'
            && $data['sender']['email'] === 'noreply@myapp.com'
            && $data['to'][0]['email'] === 'user@example.com'
            && $data['tags'] === ['onboarding']
            && $data['headers']['X-Metadata-user_id'] === '99';
    });
});

it('sends email using smtp2go driver directly via Mail facade', function () {
    config()->set('mail.mailers.smtp2go.key', 'smtp2go_direct_test_key');
    config()->set('mail.default', 'smtp2go');
    config()->set('mail.from.address', 'noreply@myapp.com');
    config()->set('mail.from.name', 'My App');

    Http::fake([
        'https://api.smtp2go.com/v3/email/send' => Http::response([
            'data' => [
                'succeeded' => 1,
                'failed' => 0,
                'email_id' => 'smtp2go_direct_001',
            ],
        ], 200),
    ]);

    Mail::to('user@example.com')->send(new StandardTestMailable);

    Http::assertSent(function (Request $request) {
        $data = $request->data();
        $customHeaders = collect($data['custom_headers']);

        return $request->url() === 'https://api.smtp2go.com/v3/email/send'
            && $data['api_key'] === 'smtp2go_direct_test_key'
            && $data['sender'] === 'My App <noreply@myapp.com>'
            && $data['to'] === ['user@example.com']
            && $customHeaders->contains(fn ($h) => $h['header'] === 'X-Tag' && $h['value'] === 'onboarding')
            && $customHeaders->contains(fn ($h) => $h['header'] === 'X-Metadata-user_id' && $h['value'] === '99');
    });
});

it('supports custom mailer config overrides in config/mail.php', function () {
    config()->set('mail.mailers.resend-marketing', [
        'transport' => 'resend',
        'key' => 're_marketing_key',
    ]);
    config()->set('mail.from.address', 'noreply@myapp.com');

    Http::fake([
        'https://api.resend.com/emails' => Http::response(['id' => 'resend_mkt_001'], 200),
    ]);

    Mail::mailer('resend-marketing')->to('subscriber@example.com')->send(new StandardTestMailable);

    Http::assertSent(function (Request $request) {
        return $request->header('Authorization')[0] === 'Bearer re_marketing_key';
    });
});

it('throws TransportException when individual driver API call fails', function () {
    config()->set('mail.mailers.resend.key', 're_invalid_key');
    config()->set('mail.default', 'resend');
    config()->set('mail.from.address', 'noreply@myapp.com');

    Http::fake([
        'https://api.resend.com/emails' => Http::response([
            'message' => 'API key is invalid',
        ], 401),
    ]);

    expect(function () {
        Mail::to('user@example.com')->send(new StandardTestMailable);
    })->toThrow(TransportException::class, 'API key is invalid');
});

it('throws TransportException when provider has no credentials configured', function () {
    config()->set('mail.mailers.resend.key', null);
    config()->set('laravel-mailer.resend.key', null);
    config()->set('mail.default', 'resend');
    config()->set('mail.from.address', 'noreply@myapp.com');

    expect(function () {
        Mail::to('user@example.com')->send(new StandardTestMailable);
    })->toThrow(TransportException::class, 'no valid API credentials');
});
