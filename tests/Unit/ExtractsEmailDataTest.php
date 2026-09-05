<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Tests\Unit;

use DateTimeImmutable;
use Eudeka\LaravelMailer\Transport\Concerns\ExtractsEmailData;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address as SymfonyAddress;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

class ExtractsEmailDataConsumer
{
    use ExtractsEmailData;

    public function testExtractEmail(SentMessage $message): Email
    {
        return $this->extractEmail($message);
    }

    public function testFormatAddress(SymfonyAddress $address): string
    {
        return $this->formatAddress($address);
    }

    /**
     * @return array{email: string, name?: string}
     */
    public function testAddressToArray(SymfonyAddress $address, ?int $maxNameLength = null): array
    {
        return $this->addressToArray($address, $maxNameLength);
    }

    /**
     * @return array<int, array{filename: string, content: string, contentType: string, isInline: bool, contentId: ?string}>
     */
    public function testExtractAttachments(Email $email): array
    {
        return $this->extractAttachments($email);
    }

    /**
     * @return array{headers: array<string, string>, tags: array<string>, metadata: array<string, string>}
     */
    public function testExtractHeadersTagsAndMetadata(Email $email): array
    {
        return $this->extractHeadersTagsAndMetadata($email);
    }

    public function testResolvePlainTextBody(mixed $text, mixed $html): ?string
    {
        return $this->resolvePlainTextBody($text, $html);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string>  $to
     */
    public function testResolveIdempotencyKey(array $headers, Email $email, string $from, array $to): string
    {
        return $this->resolveIdempotencyKey($headers, $email, $from, $to);
    }

    public function testSanitizeIdempotencyKey(string $key): string
    {
        return $this->sanitizeIdempotencyKey($key);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string>  $names
     */
    public function testExtractHeaderValue(array $headers, array $names): ?string
    {
        return $this->extractHeaderValue($headers, $names);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string>  $names
     * @return array<string, string>
     */
    public function testRemoveHeaderCaseInsensitive(array $headers, array $names): array
    {
        return $this->removeHeaderCaseInsensitive($headers, $names);
    }

    public function testFormatRetryAfterError(string $errorMessage, ?string $retryAfter, int $status): string
    {
        return $this->formatRetryAfterError($errorMessage, $retryAfter, $status);
    }
}

beforeEach(function () {
    $this->consumer = new ExtractsEmailDataConsumer;
});

describe('formatAddress', function () {
    it('formats plain email without display name', function () {
        $address = new SymfonyAddress('dev@example.com');

        expect($this->consumer->testFormatAddress($address))->toBe('dev@example.com');
    });

    it('formats address with display name using RFC-5322 quotes', function () {
        $address = new SymfonyAddress('dev@example.com', 'Developer Team');

        expect($this->consumer->testFormatAddress($address))->toBe('"Developer Team" <dev@example.com>');
    });
});

describe('addressToArray', function () {
    it('converts address without name to email-only array', function () {
        $address = new SymfonyAddress('user@example.com');

        expect($this->consumer->testAddressToArray($address))->toBe([
            'email' => 'user@example.com',
        ]);
    });

    it('converts address with name to email and name array', function () {
        $address = new SymfonyAddress('user@example.com', 'Jane Doe');

        expect($this->consumer->testAddressToArray($address))->toBe([
            'email' => 'user@example.com',
            'name' => 'Jane Doe',
        ]);
    });

    it('truncates name when maxNameLength is specified', function () {
        $longName = str_repeat('A', 100);
        $address = new SymfonyAddress('user@example.com', $longName);

        $result = $this->consumer->testAddressToArray($address, 70);

        expect($result['name'])->toHaveLength(70)
            ->and($result['name'])->toBe(str_repeat('A', 70));
    });
});

describe('resolvePlainTextBody', function () {
    it('returns explicit plain text body when provided', function () {
        $result = $this->consumer->testResolvePlainTextBody('Plain text message', '<p>HTML message</p>');

        expect($result)->toBe('Plain text message');
    });

    it('falls back to stripped html when plain text body is empty or whitespace', function () {
        $result = $this->consumer->testResolvePlainTextBody('', '<h1>Heading</h1><p>Paragraph content</p>');

        expect($result)->toBe('HeadingParagraph content');
    });

    it('returns null when both text and html are empty', function () {
        $result = $this->consumer->testResolvePlainTextBody('', '');

        expect($result)->toBeNull();
    });

    it('handles stream resource inputs', function () {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'Stream content from memory');
        rewind($stream);

        $result = $this->consumer->testResolvePlainTextBody($stream, null);
        fclose($stream);

        expect($result)->toBe('Stream content from memory');
    });
});

describe('resolveIdempotencyKey & sanitizeIdempotencyKey', function () {
    it('prefers explicit Idempotency-Key header', function () {
        $headers = ['Idempotency-Key' => 'custom-idempotent-key-123'];
        $email = (new Email)->subject('Test')->text('Hello');

        $key = $this->consumer->testResolveIdempotencyKey($headers, $email, 'sender@example.com', ['to@example.com']);

        expect($key)->toBe('custom-idempotent-key-123');
    });

    it('prefers explicit X-Idempotency-Key header when Idempotency-Key is not set', function () {
        $headers = ['X-Idempotency-Key' => 'x-idemp-456'];
        $email = (new Email)->subject('Test')->text('Hello');

        $key = $this->consumer->testResolveIdempotencyKey($headers, $email, 'sender@example.com', ['to@example.com']);

        expect($key)->toBe('x-idemp-456');
    });

    it('generates deterministic SHA-256 fingerprint when no explicit header exists', function () {
        $headers = [];
        $email = (new Email)
            ->subject('Order Confirmation')
            ->date(new DateTimeImmutable('2026-09-01 12:00:00'))
            ->text('Thank you for your order');

        $key1 = $this->consumer->testResolveIdempotencyKey($headers, $email, 'orders@example.com', ['buyer@example.com']);
        $key2 = $this->consumer->testResolveIdempotencyKey($headers, $email, 'orders@example.com', ['buyer@example.com']);

        expect($key1)->toBeString()
            ->and(strlen($key1))->toBe(64)
            ->and($key1)->toBe($key2);
    });

    it('sanitizes non-ascii characters and truncates to 256 chars max', function () {
        $dirtyKey = "valid_key_\x00\x08".str_repeat('x', 300);

        $sanitized = $this->consumer->testSanitizeIdempotencyKey($dirtyKey);

        expect(strlen($sanitized))->toBe(256)
            ->and($sanitized)->not->toContain("\x00");
    });
});

describe('extractHeadersTagsAndMetadata', function () {
    it('extracts TagHeader and MetadataHeader objects', function () {
        $email = (new Email)->subject('Headers Test');
        $email->getHeaders()->add(new TagHeader('newsletter,promo'));
        $email->getHeaders()->add(new MetadataHeader('campaign_id', 'summer_2026'));
        $email->getHeaders()->addTextHeader('X-Custom-Client', 'AcmeApp/2.0');

        $extracted = $this->consumer->testExtractHeadersTagsAndMetadata($email);

        expect($extracted['tags'])->toBe(['newsletter', 'promo'])
            ->and($extracted['metadata'])->toBe(['campaign_id' => 'summer_2026'])
            ->and($extracted['headers'])->toHaveKey('X-Custom-Client', 'AcmeApp/2.0');
    });

    it('extracts X-Tag and X-Metadata-* text headers', function () {
        $email = (new Email)->subject('Custom Text Headers');
        $email->getHeaders()->addTextHeader('X-Tag', 'alerts');
        $email->getHeaders()->addTextHeader('X-Metadata-user_id', '12345');

        $extracted = $this->consumer->testExtractHeadersTagsAndMetadata($email);

        expect($extracted['tags'])->toBe(['alerts'])
            ->and($extracted['metadata'])->toBe(['user_id' => '12345']);
    });

    it('decodes json metadata headers', function () {
        $email = (new Email)->subject('JSON Metadata');
        $email->getHeaders()->addTextHeader('X-Metadata', (string) json_encode(['env' => 'testing', 'version' => '1.0.0']));

        $extracted = $this->consumer->testExtractHeadersTagsAndMetadata($email);

        expect($extracted['metadata'])->toBe([
            'env' => 'testing',
            'version' => '1.0.0',
        ]);
    });
});

describe('extractAttachments', function () {
    it('extracts both normal and inline attachments with contentId', function () {
        $email = (new Email)->subject('Attachments Test');
        $email->attach('Regular file content', 'report.pdf', 'application/pdf');
        $email->embed('Image binary data', 'logo.png', 'image/png');

        $attachments = $this->consumer->testExtractAttachments($email);

        expect($attachments)->toHaveCount(2);

        $regular = $attachments[0];
        expect($regular['filename'])->toBe('report.pdf')
            ->and($regular['contentType'])->toBe('application/pdf')
            ->and($regular['isInline'])->toBeFalse()
            ->and($regular['content'])->toBe(base64_encode('Regular file content'));

        $inline = $attachments[1];
        expect($inline['filename'])->toBe('logo.png')
            ->and($inline['contentType'])->toBe('image/png')
            ->and($inline['isInline'])->toBeTrue()
            ->and($inline['contentId'])->toBeString();
    });
});

describe('extractEmail', function () {
    it('returns original Email instance from SentMessage', function () {
        $originalEmail = (new Email)
            ->from('from@example.com')
            ->to('to@example.com')
            ->subject('Original')
            ->text('Valid body content');
        $envelope = new Envelope(new SymfonyAddress('from@example.com'), [new SymfonyAddress('to@example.com')]);
        $sentMessage = new SentMessage($originalEmail, $envelope);

        $result = $this->consumer->testExtractEmail($sentMessage);

        expect($result)->toBe($originalEmail);
    });

    it('returns empty Email when message is not an Email or Message', function () {
        $rawMessage = new RawMessage('raw message data');
        $envelope = new Envelope(new SymfonyAddress('from@example.com'), [new SymfonyAddress('to@example.com')]);
        $sentMessage = new SentMessage($rawMessage, $envelope);

        $result = $this->consumer->testExtractEmail($sentMessage);

        expect($result)->toBeInstanceOf(Email::class);
    });
});

describe('formatRetryAfterError', function () {
    it('appends retry after duration on status 429', function () {
        $formatted = $this->consumer->testFormatRetryAfterError('Rate limit exceeded', '30', 429);

        expect($formatted)->toBe('Rate limit exceeded (retry after 30s)');
    });

    it('does not append retry after duration on non-429 status', function () {
        $formatted = $this->consumer->testFormatRetryAfterError('Internal error', '30', 500);

        expect($formatted)->toBe('Internal error');
    });

    it('does not append retry after duration when header is null', function () {
        $formatted = $this->consumer->testFormatRetryAfterError('Too Many Requests', null, 429);

        expect($formatted)->toBe('Too Many Requests');
    });
});

describe('removeHeaderCaseInsensitive & extractHeaderValue', function () {
    it('removes headers regardless of case', function () {
        $headers = [
            'Content-Type' => 'application/json',
            'Idempotency-Key' => 'key-1',
            'X-IDEMPOTENCY-KEY' => 'key-2',
            'X-Custom' => 'keep-me',
        ];

        $cleaned = $this->consumer->testRemoveHeaderCaseInsensitive($headers, ['idempotency-key', 'x-idempotency-key']);

        expect($cleaned)->toBe([
            'Content-Type' => 'application/json',
            'X-Custom' => 'keep-me',
        ]);
    });

    it('extracts header value regardless of case', function () {
        $headers = [
            'X-Topic-ID' => 'topic_alerts',
        ];

        $value = $this->consumer->testExtractHeaderValue($headers, ['x-topic-id', 'topic-id']);

        expect($value)->toBe('topic_alerts');
    });
});
