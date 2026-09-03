<?php

declare(strict_types=1);

use EmailProvider\EmailProvider\DTO\Address;
use EmailProvider\EmailProvider\DTO\EmailAttachment;
use EmailProvider\EmailProvider\DTO\NormalizedEmailPayload;
use EmailProvider\EmailProvider\Providers\BrevoProvider;
use EmailProvider\EmailProvider\Providers\ResendProvider;
use EmailProvider\EmailProvider\Providers\Smtp2goProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function createTestPayload(): NormalizedEmailPayload
{
    return new NormalizedEmailPayload(
        from: new Address('sender@example.com', 'Acme Sender'),
        to: [new Address('recipient@example.com', 'John Doe')],
        subject: 'Hello World',
        html: '<h1>Hello</h1>',
        text: 'Hello',
        cc: [new Address('cc@example.com')],
        bcc: [new Address('bcc@example.com')],
        replyTo: [new Address('reply@example.com')],
        attachments: [
            new EmailAttachment(
                filename: 'invoice.pdf',
                contentBase64: base64_encode('PDF DATA'),
                mimeType: 'application/pdf',
                isInline: false,
            ),
            new EmailAttachment(
                filename: 'logo.png',
                contentBase64: base64_encode('PNG DATA'),
                mimeType: 'image/png',
                isInline: true,
                contentId: 'logo_cid',
            ),
        ],
        headers: ['X-Track' => '12345'],
    );
}

it('formats and sends payload via Resend API', function () {
    Http::fake([
        'https://api.resend.com/emails' => Http::response(['id' => 'resend_msg_001'], 200),
    ]);

    $provider = new ResendProvider('re_test_key', 'https://api.resend.com/emails', 10);
    $payload = createTestPayload();

    $response = $provider->send($payload);

    expect($response->isSuccessful)->toBeTrue()
        ->and($response->providerName)->toBe('resend')
        ->and($response->statusCode)->toBe(200)
        ->and($response->messageId)->toBe('resend_msg_001');

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return $request->url() === 'https://api.resend.com/emails'
            && $request->header('Authorization')[0] === 'Bearer re_test_key'
            && $data['from'] === 'Acme Sender <sender@example.com>'
            && $data['to'] === ['John Doe <recipient@example.com>']
            && $data['subject'] === 'Hello World'
            && $data['html'] === '<h1>Hello</h1>'
            && count($data['attachments']) === 2;
    });
});

it('formats and sends payload via Brevo API', function () {
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<brevo_msg_001>'], 201),
    ]);

    $provider = new BrevoProvider('brevo_test_key', 'https://api.brevo.com/v3/smtp/email', 10);
    $payload = createTestPayload();

    $response = $provider->send($payload);

    expect($response->isSuccessful)->toBeTrue()
        ->and($response->providerName)->toBe('brevo')
        ->and($response->statusCode)->toBe(201)
        ->and($response->messageId)->toBe('<brevo_msg_001>');

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return $request->url() === 'https://api.brevo.com/v3/smtp/email'
            && $request->header('api-key')[0] === 'brevo_test_key'
            && $data['sender']['email'] === 'sender@example.com'
            && $data['sender']['name'] === 'Acme Sender'
            && $data['to'][0]['email'] === 'recipient@example.com'
            && $data['to'][0]['name'] === 'John Doe'
            && $data['htmlContent'] === '<h1>Hello</h1>'
            && count($data['attachment']) === 2;
    });
});

it('formats and sends payload via SMTP2GO API', function () {
    Http::fake([
        'https://api.smtp2go.com/v3/email/send' => Http::response([
            'data' => [
                'succeeded' => 1,
                'failed' => 0,
                'email_id' => 'smtp2go_msg_001',
            ],
        ], 200),
    ]);

    $provider = new Smtp2goProvider('smtp2go_test_key', 'https://api.smtp2go.com/v3/email/send', 10);
    $payload = createTestPayload();

    $response = $provider->send($payload);

    expect($response->isSuccessful)->toBeTrue()
        ->and($response->providerName)->toBe('smtp2go')
        ->and($response->statusCode)->toBe(200)
        ->and($response->messageId)->toBe('smtp2go_msg_001');

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return $request->url() === 'https://api.smtp2go.com/v3/email/send'
            && $data['api_key'] === 'smtp2go_test_key'
            && $data['sender'] === 'Acme Sender <sender@example.com>'
            && $data['to'] === ['John Doe <recipient@example.com>']
            && count($data['attachments']) === 1
            && count($data['inlines']) === 1
            && $data['inlines'][0]['cid'] === 'logo_cid';
    });
});

it('handles provider failures gracefully', function () {
    Http::fake([
        'https://api.resend.com/emails' => Http::response(['message' => 'Rate limit exceeded'], 429),
    ]);

    $provider = new ResendProvider('re_test_key', 'https://api.resend.com/emails', 10);
    $payload = createTestPayload();

    $response = $provider->send($payload);

    expect($response->isSuccessful)->toBeFalse()
        ->and($response->providerName)->toBe('resend')
        ->and($response->statusCode)->toBe(429)
        ->and($response->errorMessage)->toContain('Rate limit exceeded');
});
