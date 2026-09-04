<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Tests\Unit;

use Eudeka\LaravelMailer\Transport\ResendApiTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

it('throws exception when Resend API key is missing', function () {
    $transport = new ResendApiTransport(apiKey: null);

    $email = (new Email)
        ->from(new Address('sender@example.com', 'Sender Name'))
        ->to(new Address('recipient@example.com', 'Recipient Name'))
        ->subject('Test Subject')
        ->text('Hello World');

    $transport->send($email);
})->throws(TransportException::class, 'Resend API key is missing');

it('sends email via Resend with correct payload and headers', function () {
    Http::fake([
        'https://api.resend.com/emails' => Http::response(['id' => 're_msg_789'], 200),
    ]);

    $transport = new ResendApiTransport(apiKey: 're_valid_api_key');

    $email = (new Email)
        ->from(new Address('sender@example.com', 'Acme Corp'))
        ->to(new Address('customer@example.com', 'Customer Name'))
        ->cc(new Address('cc@example.com', 'Support'))
        ->bcc(new Address('bcc@example.com'))
        ->replyTo(new Address('support@example.com', 'Support Team'))
        ->subject('Welcome to Acme!')
        ->html('<h1>Welcome</h1>')
        ->text('Welcome to our service')
        ->attach('document body', 'doc.pdf', 'application/pdf');

    $email->getHeaders()->addTextHeader('X-Tag', 'onboarding');

    $sentMessage = $transport->send($email);

    expect($sentMessage?->getMessageId())->toBe('re_msg_789');

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return $request->url() === 'https://api.resend.com/emails'
            && $request->hasHeader('Authorization', 'Bearer re_valid_api_key')
            && $data['from'] === 'Acme Corp <sender@example.com>'
            && $data['to'] === ['Customer Name <customer@example.com>']
            && $data['cc'] === ['Support <cc@example.com>']
            && $data['bcc'] === ['bcc@example.com']
            && $data['reply_to'] === ['Support Team <support@example.com>']
            && $data['subject'] === 'Welcome to Acme!'
            && $data['html'] === '<h1>Welcome</h1>'
            && $data['text'] === 'Welcome to our service'
            && $data['attachments'][0]['filename'] === 'doc.pdf'
            && $data['attachments'][0]['content'] === base64_encode('document body')
            && $data['tags'][0]['value'] === 'onboarding';
    });
});

it('throws TransportException when Resend returns non-2xx status', function () {
    Http::fake([
        'https://api.resend.com/emails' => Http::response(['message' => 'Domain not verified'], 422),
    ]);

    $transport = new ResendApiTransport(apiKey: 're_test');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Body');

    $transport->send($email);
})->throws(TransportException::class, 'Failed sending email via Resend (HTTP 422)');

it('throws TransportException on connection timeout or network failure', function () {
    Http::fake([
        'https://api.resend.com/emails' => fn () => throw new ConnectionException('Timeout connecting to Resend'),
    ]);

    $transport = new ResendApiTransport(apiKey: 're_test');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Body');

    $transport->send($email);
})->throws(TransportException::class, 'Failed sending email via Resend: Timeout connecting to Resend');

it('returns transport string representation', function () {
    $transport = new ResendApiTransport(apiKey: 'key');

    expect((string) $transport)->toBe('resend')
        ->and($transport->apiKey())->toBe('key')
        ->and($transport->endpoint())->toBe('https://api.resend.com/emails')
        ->and($transport->timeout())->toBe(10);
});
