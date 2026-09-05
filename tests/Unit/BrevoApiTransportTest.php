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

it('truncates recipient and sender names exceeding 70 characters to prevent rejection', function () {
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo_msg_trunc'], 201),
    ]);

    $transport = new BrevoApiTransport(apiKey: 'test_key');

    $longName = str_repeat('A', 85);
    $email = (new Email)
        ->from(new Address('sender@example.com', $longName))
        ->to(new Address('recipient@example.com', $longName))
        ->cc(new Address('cc@example.com', $longName))
        ->bcc(new Address('bcc@example.com', $longName))
        ->replyTo(new Address('reply@example.com', $longName))
        ->subject('Truncation Test')
        ->text('Testing name truncation');

    $transport->send($email);

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return strlen($data['sender']['name']) === 70
            && strlen($data['to'][0]['name']) === 70
            && strlen($data['cc'][0]['name']) === 70
            && strlen($data['bcc'][0]['name']) === 70
            && strlen($data['replyTo']['name']) === 70;
    });
});

it('converts inline cid attachments to base64 data URIs in htmlContent', function () {
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo_inline_123'], 201),
    ]);

    $transport = new BrevoApiTransport(apiKey: 'test_key');

    $imageRaw = 'fake_image_binary_data';
    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Inline Image Test')
        ->html('<p>Hello <img src="cid:logo.png" alt="logo"></p>')
        ->embed($imageRaw, 'logo.png', 'image/png');

    $transport->send($email);

    Http::assertSent(function (Request $request) use ($imageRaw) {
        $data = $request->data();
        $expectedBase64 = base64_encode($imageRaw);
        $expectedDataUri = 'data:image/png;base64,'.$expectedBase64;

        return str_contains($data['htmlContent'], $expectedDataUri)
            && ! str_contains($data['htmlContent'], 'cid:logo.png')
            && $data['attachment'][0]['name'] === 'logo.png'
            && $data['attachment'][0]['content'] === $expectedBase64;
    });
});

it('maps metadata to both params and X-Metadata headers without losing data', function () {
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo_meta_123'], 201),
    ]);

    $transport = new BrevoApiTransport(apiKey: 'test_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Metadata Test')
        ->text('Testing metadata mapping');

    $email->getHeaders()->addTextHeader('X-Metadata-order_id', '98765');
    $email->getHeaders()->addTextHeader('X-Metadata-user_tier', 'premium');

    $transport->send($email);

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return isset($data['params']['order_id'])
            && $data['params']['order_id'] === '98765'
            && isset($data['params']['user_tier'])
            && $data['params']['user_tier'] === 'premium'
            && isset($data['headers']['X-Metadata-Order-Id'])
            && $data['headers']['X-Metadata-Order-Id'] === '98765'
            && isset($data['headers']['X-Metadata-User-Tier'])
            && $data['headers']['X-Metadata-User-Tier'] === 'premium';
    });
});

it('preserves user provided Idempotency-Key header', function () {
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo_idemp_custom'], 201),
    ]);

    $transport = new BrevoApiTransport(apiKey: 'test_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Idempotency Custom')
        ->text('Testing explicit idempotency key');

    $email->getHeaders()->addTextHeader('Idempotency-Key', 'my-custom-uuid-1234');

    $transport->send($email);

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return isset($data['headers']['Idempotency-Key'])
            && $data['headers']['Idempotency-Key'] === 'my-custom-uuid-1234';
    });
});

it('automatically generates deterministic Idempotency-Key when not provided', function () {
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo_idemp_auto'], 201),
    ]);

    $transport = new BrevoApiTransport(apiKey: 'test_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Idempotency Auto')
        ->text('Testing automatic idempotency key');

    $transport->send($email);

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return isset($data['headers']['Idempotency-Key'])
            && strlen($data['headers']['Idempotency-Key']) === 64;
    });
});

it('throws TransportException immediately on HTTP 429 rate limit without retry for instant failover', function () {
    $attempts = 0;
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => function () use (&$attempts) {
            $attempts++;

            return Http::response(['message' => 'Rate limit exceeded'], 429);
        },
    ]);

    $transport = new BrevoApiTransport(apiKey: 'test_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Failover 429 Test')
        ->text('Testing immediate failover on 429');

    try {
        $transport->send($email);
    } catch (TransportException $e) {
        expect($e->getMessage())->toContain('HTTP 429');
    }

    expect($attempts)->toBe(1);
});

it('throws TransportException immediately on HTTP 500 server error without retry for instant failover', function () {
    $attempts = 0;
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => function () use (&$attempts) {
            $attempts++;

            return Http::response(['message' => 'Internal server error'], 500);
        },
    ]);

    $transport = new BrevoApiTransport(apiKey: 'test_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Failover 500 Test')
        ->text('Testing immediate failover on 500');

    try {
        $transport->send($email);
    } catch (TransportException $e) {
        expect($e->getMessage())->toContain('HTTP 500');
    }

    expect($attempts)->toBe(1);
});

it('throws TransportException when 2xx response has missing or empty messageId', function () {
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response([], 200),
    ]);

    $transport = new BrevoApiTransport(apiKey: 'test_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('2xx Missing MessageId')
        ->text('Testing 2xx without messageId');

    $transport->send($email);
})->throws(TransportException::class, 'Provider returned 2xx response but did not return a valid messageId');

it('throws TransportException when 2xx response has empty messageIds array', function () {
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response(['messageIds' => []], 200),
    ]);

    $transport = new BrevoApiTransport(apiKey: 'test_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('2xx Empty MessageIds')
        ->text('Testing 2xx with empty messageIds');

    $transport->send($email);
})->throws(TransportException::class, 'Provider returned 2xx response but did not return a valid messageId');

it('throws TransportException when 2xx response contains code or error field', function () {
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response([
            'code' => 'blocked_contact',
            'message' => 'Contact is blocked',
        ], 200),
    ]);

    $transport = new BrevoApiTransport(apiKey: 'test_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('2xx Error Field')
        ->text('Testing 2xx with error code');

    $transport->send($email);
})->throws(TransportException::class, 'code: blocked_contact, message: Contact is blocked');

it('throws TransportException when 2xx response reports failed deliveries', function () {
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response([
            'failed' => 1,
        ], 200),
    ]);

    $transport = new BrevoApiTransport(apiKey: 'test_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('2xx Failed Field')
        ->text('Testing 2xx with failed count');

    $transport->send($email);
})->throws(TransportException::class, 'Provider reported delivery failure');

it('extracts detailed code and message from Brevo error responses', function () {
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response([
            'code' => 'invalid_parameter',
            'message' => 'The sender email is not valid.',
        ], 400),
    ]);

    $transport = new BrevoApiTransport(apiKey: 'test_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Error Detail Test')
        ->text('Testing error format');

    $transport->send($email);
})->throws(TransportException::class, 'Failed sending email via Brevo (HTTP 400): code: invalid_parameter, message: The sender email is not valid.');

it('extracts messageId from messageIds array when messageId is missing', function () {
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response(['messageIds' => ['brevo_batch_msg_456']], 201),
    ]);

    $transport = new BrevoApiTransport(apiKey: 'test_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('MessageIds Test')
        ->text('Testing messageIds array extraction');

    $sentMessage = $transport->send($email);

    expect($sentMessage?->getMessageId())->toBe('brevo_batch_msg_456');
});

it('automatically falls back to stripped html for textContent when text body is missing', function () {
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo_fallback_123'], 201),
    ]);

    $transport = new BrevoApiTransport(apiKey: 'test_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Fallback Text Test')
        ->html('<h1>Hello World</h1><p>This is HTML content.</p>');

    $transport->send($email);

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return $data['textContent'] === 'Hello WorldThis is HTML content.'
            || str_contains($data['textContent'], 'Hello World');
    });
});

it('includes retry-after details when Brevo returns HTTP 429 rate limit', function () {
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response(
            ['message' => 'Too many requests.'],
            429,
            ['Retry-After' => '5'],
        ),
    ]);

    $transport = new BrevoApiTransport(apiKey: 'test_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Rate Limit Test')
        ->text('Testing 429 Retry-After');

    $transport->send($email);
})->throws(TransportException::class, 'Failed sending email via Brevo (HTTP 429): Too many requests. (retry after 5s)');

it('sends User-Agent header with expected package identifier', function () {
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo_ua_123'], 201),
    ]);

    $transport = new BrevoApiTransport(apiKey: 'test_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('UA Test')
        ->text('Testing User-Agent');

    $transport->send($email);

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('User-Agent', 'eudeka-laravel-mailer/1.0');
    });
});
