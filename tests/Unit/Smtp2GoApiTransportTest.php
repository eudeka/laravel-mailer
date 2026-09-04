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
            && $request->hasHeader('api-key', 'smtp2go_secret_key')
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
