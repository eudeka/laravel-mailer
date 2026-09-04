<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Tests\Unit;

use Eudeka\LaravelMailer\Transport\Smtp2GoApiTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

it('throws exception when SMTP2GO API key is missing', function () {
    $transport = new Smtp2GoApiTransport(apiKey: null);

    $email = (new Email)
        ->from(new Address('sender@example.com', 'Sender Name'))
        ->to(new Address('recipient@example.com', 'Recipient Name'))
        ->subject('Test Subject')
        ->text('Hello World');

    $transport->send($email);
})->throws(TransportException::class, 'SMTP2GO API key is missing');

it('sends email via SMTP2GO with correct payload and headers', function () {
    Http::fake([
        'https://api.smtp2go.com/v3/email/send' => Http::response([
            'data' => [
                'succeeded' => 1,
                'failed' => 0,
                'email_id' => 'smtp2go_mail_456',
            ],
        ], 200),
    ]);

    $transport = new Smtp2GoApiTransport(apiKey: 'smtp2go_secret_key');

    $email = (new Email)
        ->from(new Address('sender@example.com', 'Mail Agent'))
        ->to(new Address('dest@example.com', 'Destination'))
        ->cc(new Address('manager@example.com'))
        ->bcc(new Address('archive@example.com'))
        ->subject('Weekly Status Report')
        ->html('<b>Status OK</b>')
        ->text('Status OK')
        ->attach('pdf raw data', 'report.pdf', 'application/pdf');

    $email->getHeaders()->addTextHeader('X-Custom-Tracking', 'report-123');

    $sentMessage = $transport->send($email);

    expect($sentMessage?->getMessageId())->toBe('smtp2go_mail_456');

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return $request->url() === 'https://api.smtp2go.com/v3/email/send'
            && $request->hasHeader('X-Smtp2go-Api-Key', 'smtp2go_secret_key')
            && $request->hasHeader('api-key', 'smtp2go_secret_key')
            && $data['fastaccept'] === false
            && $data['sender'] === 'Mail Agent <sender@example.com>'
            && $data['to'] === ['Destination <dest@example.com>']
            && $data['cc'] === ['manager@example.com']
            && $data['bcc'] === ['archive@example.com']
            && $data['subject'] === 'Weekly Status Report'
            && $data['html_body'] === '<b>Status OK</b>'
            && $data['text_body'] === 'Status OK'
            && $data['attachments'][0]['filename'] === 'report.pdf'
            && $data['attachments'][0]['fileblob'] === base64_encode('pdf raw data')
            && $data['attachments'][0]['mimetype'] === 'application/pdf'
            && $data['custom_headers'][0]['header'] === 'X-Custom-Tracking'
            && $data['custom_headers'][0]['value'] === 'report-123';
    });
});

it('preserves Reply-To and threading headers in custom_headers while filtering forbidden headers', function () {
    Http::fake([
        'https://api.smtp2go.com/v3/email/send' => Http::response([
            'data' => [
                'succeeded' => 1,
                'failed' => 0,
                'email_id' => 'smtp2go_headers_test',
            ],
        ], 200),
    ]);

    $transport = new Smtp2GoApiTransport(apiKey: 'key_123');

    $email = (new Email)
        ->from(new Address('support@example.com', 'Support'))
        ->to(new Address('client@example.com', 'Client'))
        ->replyTo(new Address('helpdesk@example.com', 'Help Desk'))
        ->subject('Re: Ticket #1234')
        ->html('<p>Here is your answer.</p>');

    $headers = $email->getHeaders();
    $headers->addTextHeader('In-Reply-To', '<ticket-parent@example.com>');
    $headers->addTextHeader('References', '<ticket-root@example.com> <ticket-parent@example.com>');
    $headers->addTextHeader('Content-Type', 'text/html; charset=utf-8');
    $headers->addTextHeader('MIME-Version', '1.0');
    $headers->addTextHeader('X-App-Campaign', 'summer-2026');

    $transport->send($email);

    Http::assertSent(function (Request $request) {
        $customHeaders = collect($request->data()['custom_headers'] ?? []);

        $headerNames = $customHeaders->pluck('header')->map(fn (string $h) => strtolower($h))->all();

        // Must contain Reply-To, In-Reply-To, References, and custom tracking headers
        expect($headerNames)->toContain('reply-to')
            ->and($headerNames)->toContain('in-reply-to')
            ->and($headerNames)->toContain('references')
            ->and($headerNames)->toContain('x-app-campaign')
            // Must strictly filter out forbidden headers
            ->and($headerNames)->not->toContain('content-type')
            ->and($headerNames)->not->toContain('content-transfer-encoding')
            ->and($headerNames)->not->toContain('mime-version')
            ->and($headerNames)->not->toContain('from')
            ->and($headerNames)->not->toContain('to')
            ->and($headerNames)->not->toContain('subject');

        $replyTo = $customHeaders->firstWhere('header', 'Reply-To');
        expect($replyTo['value'])->toBe('Help Desk <helpdesk@example.com>');

        $inReplyTo = $customHeaders->firstWhere('header', 'In-Reply-To');
        expect($inReplyTo['value'])->toBe('<ticket-parent@example.com>');

        return true;
    });
});

it('separates standard attachments from inline embedded images with CID matching', function () {
    Http::fake([
        'https://api.smtp2go.com/v3/email/send' => Http::response([
            'data' => [
                'succeeded' => 1,
                'failed' => 0,
                'email_id' => 'smtp2go_inline_test',
            ],
        ], 200),
    ]);

    $transport = new Smtp2GoApiTransport(apiKey: 'key_123');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Email with Logo and Invoice')
        ->attach('pdf content', 'invoice.pdf', 'application/pdf');

    // Attach inline image
    $email->embed('image bytes', 'brand_logo', 'image/png');
    // Set HTML referencing the embedded image
    $email->html('<h1>Hello</h1><img src="cid:brand_logo" />');

    $transport->send($email);

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        // invoice.pdf must be in attachments
        expect($data['attachments'])->toHaveCount(1)
            ->and($data['attachments'][0]['filename'])->toBe('invoice.pdf')
            ->and($data['attachments'][0]['fileblob'])->toBe(base64_encode('pdf content'))
            ->and($data['attachments'][0]['mimetype'])->toBe('application/pdf');

        // brand_logo must be in inlines
        expect($data['inlines'])->toHaveCount(1)
            ->and($data['inlines'][0]['filename'])->toBe('brand_logo')
            ->and($data['inlines'][0]['fileblob'])->toBe(base64_encode('image bytes'))
            ->and($data['inlines'][0]['mimetype'])->toBe('image/png');

        return true;
    });
});

it('generates fallback text_body automatically when only html_body is provided', function () {
    Http::fake([
        'https://api.smtp2go.com/v3/email/send' => Http::response([
            'data' => [
                'succeeded' => 1,
                'failed' => 0,
                'email_id' => 'smtp2go_fallback_test',
            ],
        ], 200),
    ]);

    $transport = new Smtp2GoApiTransport(apiKey: 'key_123');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('HTML Only Message')
        ->html('<h1>Welcome to our service!</h1><p>Click <a href="https://example.com">here</a> to start.</p>');

    $transport->send($email);

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        expect($data['html_body'])->toBe('<h1>Welcome to our service!</h1><p>Click <a href="https://example.com">here</a> to start.</p>')
            ->and($data['text_body'])->toBe('Welcome to our service!Click here to start.');

        return true;
    });
});

it('maps tags and metadata headers to X-Tag and X-Metadata custom headers', function () {
    Http::fake([
        'https://api.smtp2go.com/v3/email/send' => Http::response([
            'data' => [
                'succeeded' => 1,
                'failed' => 0,
                'email_id' => 'smtp2go_tags_meta_test',
            ],
        ], 200),
    ]);

    $transport = new Smtp2GoApiTransport(apiKey: 'key_123');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Tags and Metadata Test')
        ->text('Testing tracking data');

    $email->getHeaders()->addTextHeader('X-Tag', 'newsletter, promo');
    $email->getHeaders()->addTextHeader('X-Metadata-user-id', 'user_999');
    $email->getHeaders()->addTextHeader('X-Metadata-tenant', 'acme');

    $transport->send($email);

    Http::assertSent(function (Request $request) {
        $customHeaders = collect($request->data()['custom_headers'] ?? []);

        $tags = $customHeaders->where('header', 'X-Tag')->pluck('value')->all();
        expect($tags)->toContain('newsletter')
            ->and($tags)->toContain('promo');

        $userId = $customHeaders->firstWhere('header', 'X-Metadata-user-id');
        expect($userId['value'])->toBe('user_999');

        $tenant = $customHeaders->firstWhere('header', 'X-Metadata-tenant');
        expect($tenant['value'])->toBe('acme');

        return true;
    });
});

it('throws TransportException with all failures when multiple failure messages returned', function () {
    Http::fake([
        'https://api.smtp2go.com/v3/email/send' => Http::response([
            'data' => [
                'succeeded' => 0,
                'failed' => 2,
                'failures' => [
                    'Recipient bounce: mailbox unavailable',
                    'Domain does not have valid MX records',
                ],
            ],
        ], 200),
    ]);

    $transport = new Smtp2GoApiTransport(apiKey: 'key_123');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('bad1@example.com')
        ->subject('Test')
        ->text('Body');

    $transport->send($email);
})->throws(TransportException::class, 'Failed sending email via SMTP2GO: Recipient bounce: mailbox unavailable; Domain does not have valid MX records');

it('includes error_code in TransportException when SMTP2GO returns 4xx with error_code', function () {
    Http::fake([
        'https://api.smtp2go.com/v3/email/send' => Http::response([
            'data' => [
                'error_code' => 'E_ApiResponseCodes.ENDPOINT_PERMISSION_DENIED',
                'error' => 'You do not have permission to access this API endpoint',
            ],
        ], 400),
    ]);

    $transport = new Smtp2GoApiTransport(apiKey: 'key_123');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Body');

    $transport->send($email);
})->throws(TransportException::class, 'Failed sending email via SMTP2GO (HTTP 400): [E_ApiResponseCodes.ENDPOINT_PERMISSION_DENIED] You do not have permission to access this API endpoint');

it('supports custom endpoint override via constructor and defaults to global', function () {
    $defaultTransport = new Smtp2GoApiTransport(apiKey: 'key');
    expect($defaultTransport->endpoint())->toBe('https://api.smtp2go.com/v3/email/send');

    $customTransport = new Smtp2GoApiTransport(apiKey: 'key', endpoint: 'https://custom-proxy.internal/v3/send');
    expect($customTransport->endpoint())->toBe('https://custom-proxy.internal/v3/send');
});

it('throws TransportException when SMTP2GO returns 200 with data.succeeded === 0', function () {
    Http::fake([
        'https://api.smtp2go.com/v3/email/send' => Http::response([
            'data' => [
                'succeeded' => 0,
                'failed' => 0,
                'email_id' => '',
            ],
        ], 200),
    ]);

    $transport = new Smtp2GoApiTransport(apiKey: 'key_123');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Body');

    $transport->send($email);
})->throws(TransportException::class, 'Failed sending email via SMTP2GO: Provider reported that 0 emails were delivered.');

it('throws TransportException when SMTP2GO returns 200 with missing or empty email_id', function () {
    Http::fake([
        'https://api.smtp2go.com/v3/email/send' => Http::response([
            'data' => [
                'succeeded' => 1,
                'failed' => 0,
            ],
        ], 200),
    ]);

    $transport = new Smtp2GoApiTransport(apiKey: 'key_123');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Body');

    $transport->send($email);
})->throws(TransportException::class, 'Failed sending email via SMTP2GO: Provider did not return an email_id indicating delivery acceptance.');

it('throws TransportException when SMTP2GO returns 200 with missing or invalid data property', function () {
    Http::fake([
        'https://api.smtp2go.com/v3/email/send' => Http::response([
            'request_id' => 'abc-123',
            'data' => null,
        ], 200),
    ]);

    $transport = new Smtp2GoApiTransport(apiKey: 'key_123');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Body');

    $transport->send($email);
})->throws(TransportException::class, 'Failed sending email via SMTP2GO: Missing data payload in provider response.');

it('throws TransportException when SMTP2GO returns 200 with error property in data', function () {
    Http::fake([
        'https://api.smtp2go.com/v3/email/send' => Http::response([
            'data' => [
                'error_code' => 'E_ApiResponseCodes.RATE_LIMIT_EXCEEDED',
                'error' => 'API key ratelimit exceeded',
            ],
        ], 200),
    ]);

    $transport = new Smtp2GoApiTransport(apiKey: 'key_123');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Body');

    $transport->send($email);
})->throws(TransportException::class, 'Failed sending email via SMTP2GO: [E_ApiResponseCodes.RATE_LIMIT_EXCEEDED] API key ratelimit exceeded');

it('throws TransportException when SMTP2GO returns non-2xx status', function () {
    Http::fake([
        'https://api.smtp2go.com/v3/email/send' => Http::response(['message' => 'Rate limit exceeded'], 429),
    ]);

    $transport = new Smtp2GoApiTransport(apiKey: 'key_123');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Body');

    $transport->send($email);
})->throws(TransportException::class, 'Failed sending email via SMTP2GO (HTTP 429)');

it('throws TransportException when SMTP2GO returns 200 with data.failed > 0', function () {
    Http::fake([
        'https://api.smtp2go.com/v3/email/send' => Http::response([
            'data' => [
                'succeeded' => 0,
                'failed' => 1,
                'error' => 'Sender domain not verified',
            ],
        ], 200),
    ]);

    $transport = new Smtp2GoApiTransport(apiKey: 'key_123');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Body');

    $transport->send($email);
})->throws(TransportException::class, 'Failed sending email via SMTP2GO: Sender domain not verified');

it('throws TransportException on connection timeout or network failure', function () {
    Http::fake([
        'https://api.smtp2go.com/v3/email/send' => fn () => throw new ConnectionException('Connection timeout to SMTP2GO'),
    ]);

    $transport = new Smtp2GoApiTransport(apiKey: 'key_123');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Body');

    $transport->send($email);
})->throws(TransportException::class, 'Failed sending email via SMTP2GO: Connection timeout to SMTP2GO');

it('returns transport string representation', function () {
    $transport = new Smtp2GoApiTransport(apiKey: 'key');

    expect((string) $transport)->toBe('smtp2go')
        ->and($transport->apiKey())->toBe('key')
        ->and($transport->endpoint())->toBe('https://api.smtp2go.com/v3/email/send')
        ->and($transport->timeout())->toBe(10);
});
