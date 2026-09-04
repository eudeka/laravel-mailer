<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Tests\Unit;

use Eudeka\LaravelMailer\Transport\BrevoApiTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

it('throws exception when Brevo API key is missing', function () {
    $transport = new BrevoApiTransport(apiKey: null);

    $email = (new Email)
        ->from(new Address('sender@example.com', 'Sender Name'))
        ->to(new Address('recipient@example.com', 'Recipient Name'))
        ->subject('Test Subject')
        ->text('Hello World');

    $transport->send($email);
})->throws(TransportException::class, 'Brevo API key is missing');

it('sends email via Brevo with correct payload and headers', function () {
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo_msg_123'], 201),
    ]);

    $transport = new BrevoApiTransport(apiKey: 'brevo_secret_key');

    $email = (new Email)
        ->from(new Address('sender@example.com', 'Sender Name'))
        ->to(new Address('recipient@example.com', 'Recipient Name'))
        ->cc(new Address('cc@example.com', 'CC Person'))
        ->bcc(new Address('bcc@example.com'))
        ->replyTo(new Address('reply@example.com'))
        ->subject('Invoice #101')
        ->html('<p>Invoice HTML body</p>')
        ->text('Invoice text body')
        ->attach('file content string', 'invoice.txt', 'text/plain');

    $email->getHeaders()->addTextHeader('X-Custom-Header', 'custom-value');
    $email->getHeaders()->addTextHeader('X-Tag', 'monthly,invoice');

    $sentMessage = $transport->send($email);

    expect($sentMessage?->getMessageId())->toBe('brevo_msg_123');

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return $request->url() === 'https://api.brevo.com/v3/smtp/email'
            && $request->hasHeader('api-key', 'brevo_secret_key')
            && $data['sender']['email'] === 'sender@example.com'
            && $data['sender']['name'] === 'Sender Name'
            && $data['to'][0]['email'] === 'recipient@example.com'
            && $data['to'][0]['name'] === 'Recipient Name'
            && $data['cc'][0]['email'] === 'cc@example.com'
            && $data['bcc'][0]['email'] === 'bcc@example.com'
            && $data['replyTo']['email'] === 'reply@example.com'
            && $data['subject'] === 'Invoice #101'
            && $data['htmlContent'] === '<p>Invoice HTML body</p>'
            && $data['textContent'] === 'Invoice text body'
            && $data['attachment'][0]['name'] === 'invoice.txt'
            && $data['attachment'][0]['content'] === base64_encode('file content string')
            && $data['headers']['X-Custom-Header'] === 'custom-value'
            && in_array('monthly', $data['tags'], true);
    });
});

it('throws TransportException when Brevo returns non-2xx status', function () {
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response(['message' => 'Invalid API Key'], 401),
    ]);

    $transport = new BrevoApiTransport(apiKey: 'bad_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Body');

    $transport->send($email);
})->throws(TransportException::class, 'Failed sending email via Brevo (HTTP 401)');

it('throws TransportException on connection timeout or network failure', function () {
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => fn () => throw new ConnectionException('Connection timed out'),
    ]);

    $transport = new BrevoApiTransport(apiKey: 'test_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Body');

    $transport->send($email);
})->throws(TransportException::class, 'Failed sending email via Brevo: Connection timed out');

it('returns transport string representation', function () {
    $transport = new BrevoApiTransport(apiKey: 'key');

    expect((string) $transport)->toBe('brevo')
        ->and($transport->apiKey())->toBe('key')
        ->and($transport->endpoint())->toBe('https://api.brevo.com/v3/smtp/email')
        ->and($transport->timeout())->toBe(10);
});
