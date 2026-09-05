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

it('throws TransportException when to recipient list is empty', function () {
    $transport = new ResendApiTransport(apiKey: 're_valid_api_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->cc('cc@example.com')
        ->subject('No To')
        ->text('Hello');

    $transport->send($email);
})->throws(TransportException::class, 'Resend requires at least one "to" recipient.');

it('falls back to noreply@example.com when sender from is missing', function () {
    Http::fake([
        'https://api.resend.com/emails' => Http::response(['id' => 're_msg_noreply'], 200),
    ]);

    $transport = new ResendApiTransport(apiKey: 're_valid_api_key');

    $email = (new Email)
        ->sender('technical-sender@example.com')
        ->to('customer@example.com')
        ->subject('No From')
        ->text('Hello');

    $transport->send($email);

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return $data['from'] === 'noreply@example.com';
    });
});

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
            && $request->header('User-Agent')[0] === 'eudeka-laravel-mailer/1.0'
            && $request->hasHeader('Idempotency-Key')
            && $data['from'] === '"Acme Corp" <sender@example.com>'
            && $data['to'] === ['"Customer Name" <customer@example.com>']
            && $data['cc'] === ['"Support" <cc@example.com>']
            && $data['bcc'] === ['bcc@example.com']
            && $data['reply_to'] === ['"Support Team" <support@example.com>']
            && $data['subject'] === 'Welcome to Acme!'
            && $data['html'] === '<h1>Welcome</h1>'
            && $data['text'] === 'Welcome to our service'
            && $data['attachments'][0]['filename'] === 'doc.pdf'
            && $data['attachments'][0]['content'] === base64_encode('document body')
            && $data['attachments'][0]['content_type'] === 'application/pdf'
            && $data['tags'][0]['name'] === 'tag'
            && $data['tags'][0]['value'] === 'onboarding';
    });
});

it('preserves inline image attachment content_id and content_type', function () {
    Http::fake([
        'https://api.resend.com/emails' => Http::response(['id' => 're_inline_123'], 200),
    ]);

    $transport = new ResendApiTransport(apiKey: 're_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Inline Logo Test')
        ->html('<img src="cid:logo_img">')
        ->embed('fake-image-bytes', 'logo.png', 'image/png');

    $transport->send($email);

    Http::assertSent(function (Request $request) {
        $attachments = $request->data()['attachments'] ?? [];

        return count($attachments) === 1
            && $attachments[0]['filename'] === 'logo.png'
            && $attachments[0]['content_type'] === 'image/png'
            && ! empty($attachments[0]['content_id']);
    });
});

it('merges and sanitizes Laravel tags and metadata into Resend tags format', function () {
    Http::fake([
        'https://api.resend.com/emails' => Http::response(['id' => 're_meta_123'], 200),
    ]);

    $transport = new ResendApiTransport(apiKey: 're_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Tags and Metadata Test')
        ->text('Test Content');

    $email->getHeaders()->addTextHeader('X-Metadata-user_id', '12345');
    $email->getHeaders()->addTextHeader('X-Metadata-campaign-name', 'Spring Promo 2026!');
    $email->getHeaders()->addTextHeader('X-Tag', 'newsletter, vip customer');

    $transport->send($email);

    Http::assertSent(function (Request $request) {
        $tags = $request->data()['tags'] ?? [];

        return count($tags) === 4
            && $tags[0] === ['name' => 'user_id', 'value' => '12345']
            && $tags[1] === ['name' => 'campaign-name', 'value' => 'Spring_Promo_2026']
            && $tags[2] === ['name' => 'tag', 'value' => 'newsletter']
            && $tags[3] === ['name' => 'tag', 'value' => 'vip_customer'];
    });
});

it('extracts explicit Idempotency-Key and X-Scheduled-At and strips them from MIME headers', function () {
    Http::fake([
        'https://api.resend.com/emails' => Http::response(['id' => 're_sched_123'], 200),
    ]);

    $transport = new ResendApiTransport(apiKey: 're_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Scheduled with Idempotency')
        ->text('Test Content');

    $email->getHeaders()->addTextHeader('Idempotency-Key', 'custom-uuid-key-999');
    $email->getHeaders()->addTextHeader('X-Scheduled-At', '2026-10-01T08:00:00Z');
    $email->getHeaders()->addTextHeader('List-Unsubscribe', '<https://example.com/unsub>');

    $transport->send($email);

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return $request->header('Idempotency-Key')[0] === 'custom-uuid-key-999'
            && $data['scheduled_at'] === '2026-10-01T08:00:00Z'
            && isset($data['headers']['List-Unsubscribe'])
            && ! isset($data['headers']['Idempotency-Key'])
            && ! isset($data['headers']['X-Scheduled-At']);
    });
});

it('includes error name and message in TransportException on Resend API failure', function () {
    Http::fake([
        'https://api.resend.com/emails' => Http::response([
            'statusCode' => 422,
            'name' => 'validation_error',
            'message' => 'The to field is required.',
        ], 422),
    ]);

    $transport = new ResendApiTransport(apiKey: 're_test');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Body');

    $transport->send($email);
})->throws(TransportException::class, 'Failed sending email via Resend (HTTP 422): [validation_error] The to field is required.');

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

it('throws TransportException when Resend returns 2xx without a valid message ID', function () {
    Http::fake([
        'https://api.resend.com/emails' => Http::response(['id' => ''], 200),
    ]);

    $transport = new ResendApiTransport(apiKey: 're_test');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Body');

    $transport->send($email);
})->throws(TransportException::class, 'Failed sending email via Resend: HTTP 200 received but no valid message ID returned');

it('throws TransportException when Resend returns 2xx with an error payload', function () {
    Http::fake([
        'https://api.resend.com/emails' => Http::response([
            'data' => null,
            'error' => [
                'name' => 'suppressed',
                'message' => 'Recipient is on account suppression list',
            ],
        ], 200),
    ]);

    $transport = new ResendApiTransport(apiKey: 're_test');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Body');

    $transport->send($email);
})->throws(TransportException::class, 'Failed sending email via Resend (HTTP 200 returned error): [suppressed] Recipient is on account suppression list');

it('includes retry-after header details when HTTP 429 rate limit is hit', function () {
    Http::fake([
        'https://api.resend.com/emails' => Http::response(
            [
                'name' => 'rate_limit_exceeded',
                'message' => 'Too many requests.',
            ],
            429,
            ['Retry-After' => '3'],
        ),
    ]);

    $transport = new ResendApiTransport(apiKey: 're_test');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Test')
        ->text('Body');

    $transport->send($email);
})->throws(TransportException::class, 'Failed sending email via Resend (HTTP 429): [rate_limit_exceeded] Too many requests. (retry after 3s)');

it('extracts X-Topic-Id and passes it to payload while stripping from MIME headers', function () {
    Http::fake([
        'https://api.resend.com/emails' => Http::response(['id' => 're_topic_123'], 200),
    ]);

    $transport = new ResendApiTransport(apiKey: 're_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Topic Test')
        ->text('Newsletter content');

    $email->getHeaders()->addTextHeader('X-Topic-Id', 'top_abc123');

    $transport->send($email);

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return $data['topic_id'] === 'top_abc123'
            && ! isset($data['headers']['X-Topic-Id']);
    });
});

it('returns transport string representation', function () {
    $transport = new ResendApiTransport(apiKey: 'key');

    expect((string) $transport)->toBe('resend')
        ->and($transport->apiKey())->toBe('key')
        ->and($transport->endpoint())->toBe('https://api.resend.com/emails')
        ->and($transport->timeout())->toBe(10);
});

it('automatically falls back to stripped html for text body when text is missing', function () {
    Http::fake([
        'https://api.resend.com/emails' => Http::response(['id' => 're_fallback_123'], 200),
    ]);

    $transport = new ResendApiTransport(apiKey: 're_key');

    $email = (new Email)
        ->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Fallback Text Test')
        ->html('<h2>Important Update</h2><p>Here are the details.</p>');

    $transport->send($email);

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return isset($data['text'])
            && (str_contains($data['text'], 'Important Update'));
    });
});
