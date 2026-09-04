<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Transport;

use Eudeka\LaravelMailer\Transport\Concerns\ExtractsEmailData;
use Illuminate\Support\Facades\Http;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

final class ResendApiTransport extends AbstractTransport
{
    use ExtractsEmailData;

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly string $endpoint = 'https://api.resend.com/emails',
        private readonly int $timeout = 10,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger);
    }

    public function apiKey(): ?string
    {
        return $this->apiKey;
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->apiKey === null || trim($this->apiKey) === '') {
            throw new TransportException('Resend API key is missing or not configured.');
        }

        $email = $this->extractEmail($message);

        $fromAddresses = $email->getFrom();
        $from = isset($fromAddresses[0])
            ? $this->formatAddress($fromAddresses[0])
            : 'noreply@example.com';

        $to = array_map(fn (Address $addr): string => $this->formatAddress($addr), $email->getTo());

        if ($to === []) {
            throw new TransportException('Resend requires at least one "to" recipient.');
        }

        /** @var array<string, mixed> $payload */
        $payload = [
            'from' => $from,
            'to' => $to,
            'subject' => (string) $email->getSubject(),
        ];

        $html = $email->getHtmlBody();

        if (is_string($html) && $html !== '') {
            $payload['html'] = $html;
        }

        $text = $email->getTextBody();

        if (is_string($text) && $text !== '') {
            $payload['text'] = $text;
        }

        $cc = array_map(fn (Address $addr): string => $this->formatAddress($addr), $email->getCc());

        if ($cc !== []) {
            $payload['cc'] = $cc;
        }

        $bcc = array_map(fn (Address $addr): string => $this->formatAddress($addr), $email->getBcc());

        if ($bcc !== []) {
            $payload['bcc'] = $bcc;
        }

        $replyTo = array_map(fn (Address $addr): string => $this->formatAddress($addr), $email->getReplyTo());

        if ($replyTo !== []) {
            $payload['reply_to'] = $replyTo;
        }

        $rawAttachments = $this->extractAttachments($email);

        if ($rawAttachments !== []) {
            $payload['attachments'] = array_map(
                function (array $att): array {
                    /** @var array{filename: string, content: string, content_type?: string, content_id?: string} $item */
                    $item = [
                        'filename' => $att['filename'],
                        'content' => $att['content'],
                    ];

                    if (! empty($att['contentType'])) {
                        $item['content_type'] = $att['contentType'];
                    }

                    if (! empty($att['contentId'])) {
                        $item['content_id'] = $att['contentId'];
                    }

                    return $item;
                },
                $rawAttachments,
            );
        }

        $extracted = $this->extractHeadersTagsAndMetadata($email);
        $headers = $extracted['headers'];

        // Extract and resolve Idempotency-Key (from headers or auto-generated)
        $idempotencyKey = $this->resolveIdempotencyKey($headers, $email, $from, $to);
        $headers = $this->removeHeaderCaseInsensitive($headers, ['idempotency-key', 'x-idempotency-key']);

        // Extract and resolve Scheduled-At
        $scheduledAt = $this->extractHeaderValue($headers, ['x-scheduled-at', 'scheduled-at']);

        if ($scheduledAt !== null && trim($scheduledAt) !== '') {
            $payload['scheduled_at'] = trim($scheduledAt);
            $headers = $this->removeHeaderCaseInsensitive($headers, ['x-scheduled-at', 'scheduled-at']);
        }

        // Extract and resolve Topic ID
        $topicId = $this->extractHeaderValue($headers, ['x-topic-id', 'topic-id']);

        if ($topicId !== null && trim($topicId) !== '') {
            $payload['topic_id'] = trim($topicId);
            $headers = $this->removeHeaderCaseInsensitive($headers, ['x-topic-id', 'topic-id']);
        }

        if ($headers !== []) {
            $payload['headers'] = $headers;
        }

        $resendTags = $this->formatResendTags($extracted['tags'], $extracted['metadata']);

        if ($resendTags !== []) {
            $payload['tags'] = $resendTags;
        }

        $requestHeaders = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'eudeka-laravel-mailer/1.0',
            'Idempotency-Key' => $idempotencyKey,
        ];

        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders($requestHeaders)
                ->timeout($this->timeout)
                ->post($this->endpoint, $payload);
        } catch (Throwable $e) {
            throw new TransportException(sprintf('Failed sending email via Resend: %s', $e->getMessage()), 0, $e);
        }

        if (! $response->successful()) {
            $json = $response->json();
            $errorMsg = null;
            $errorType = null;

            if (is_array($json)) {
                if (isset($json['error']) && is_array($json['error'])) {
                    $errorMsg = $json['error']['message'] ?? null;
                    $errorType = $json['error']['name'] ?? null;
                } else {
                    $errorMsg = $json['message'] ?? null;
                    $errorType = $json['name'] ?? null;
                }
            }

            $errorMsg = $errorMsg ?? $response->body();

            $formattedError = is_string($errorType) && $errorType !== ''
                ? sprintf('[%s] %s', $errorType, is_string($errorMsg) ? $errorMsg : (string) json_encode($errorMsg))
                : (is_string($errorMsg) ? $errorMsg : (string) json_encode($errorMsg));

            $retryAfter = $response->header('Retry-After');

            if ($response->status() === 429 && trim($retryAfter) !== '') {
                $formattedError .= sprintf(' (retry after %ss)', trim($retryAfter));
            }

            throw new TransportException(sprintf(
                'Failed sending email via Resend (HTTP %d): %s',
                $response->status(),
                $formattedError,
            ));
        }

        $json = $response->json();

        if (is_array($json) && isset($json['error'])) {
            $errorPayload = $json['error'];
            $errorMsg = '';
            $errorType = null;

            if (is_array($errorPayload)) {
                $msg = $errorPayload['message'] ?? null;
                $errorMsg = is_string($msg) ? $msg : (string) json_encode($errorPayload);
                $name = $errorPayload['name'] ?? null;
                $errorType = is_string($name) ? $name : null;
            } elseif (is_string($errorPayload)) {
                $errorMsg = $errorPayload;
            } else {
                $errorMsg = (string) json_encode($errorPayload);
            }

            $formattedError = $errorType !== null && $errorType !== ''
                ? sprintf('[%s] %s', $errorType, $errorMsg)
                : $errorMsg;

            throw new TransportException(sprintf(
                'Failed sending email via Resend (HTTP %d returned error): %s',
                $response->status(),
                $formattedError,
            ));
        }

        $messageId = is_array($json) ? ($json['id'] ?? null) : null;

        if (! is_string($messageId) || trim($messageId) === '') {
            throw new TransportException(sprintf(
                'Failed sending email via Resend: HTTP %d received but no valid message ID returned (%s).',
                $response->status(),
                $response->body(),
            ));
        }

        $message->setMessageId(trim($messageId));
        $message->appendDebug('Sent via Resend API');
    }

    /**
     * Resolve the idempotency key from email headers or auto-generate one.
     *
     * @param  array<string, string>  $headers
     * @param  array<string>  $to
     */
    private function resolveIdempotencyKey(array $headers, Email $email, string $from, array $to): string
    {
        $explicit = $this->extractHeaderValue($headers, ['idempotency-key', 'x-idempotency-key']);

        if ($explicit !== null && trim($explicit) !== '') {
            return $this->sanitizeIdempotencyKey($explicit);
        }

        $messageIdHeader = $email->getHeaders()->getHeaderBody('Message-ID');

        if (is_string($messageIdHeader) && trim($messageIdHeader) !== '') {
            return $this->sanitizeIdempotencyKey(trim($messageIdHeader, '<> '));
        }

        $fingerprint = sprintf(
            '%s|%s|%s|%s',
            $from,
            implode(',', $to),
            (string) $email->getSubject(),
            (string) $email->getDate()?->getTimestamp(),
        );

        return $this->sanitizeIdempotencyKey(hash('sha256', $fingerprint));
    }

    /**
     * Sanitize idempotency key to ASCII characters up to 256 chars max.
     */
    private function sanitizeIdempotencyKey(string $key): string
    {
        $sanitized = (string) preg_replace('/[^\x20-\x7E]/', '', trim($key));

        return mb_substr($sanitized !== '' ? $sanitized : hash('sha256', (string) microtime(true)), 0, 256);
    }

    /**
     * Extract header value case-insensitively.
     *
     * @param  array<string, string>  $headers
     * @param  array<string>  $names
     */
    private function extractHeaderValue(array $headers, array $names): ?string
    {
        $lowerNames = array_map('strtolower', $names);

        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $lowerNames, true)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Remove header entries case-insensitively.
     *
     * @param  array<string, string>  $headers
     * @param  array<string>  $names
     * @return array<string, string>
     */
    private function removeHeaderCaseInsensitive(array $headers, array $names): array
    {
        $lowerNames = array_map('strtolower', $names);
        $result = [];

        foreach ($headers as $key => $value) {
            if (! in_array(strtolower($key), $lowerNames, true)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Format tags and metadata into Resend's tags array.
     *
     * @param  array<string>  $tags
     * @param  array<string, string>  $metadata
     * @return array<int, array{name: string, value: string}>
     */
    private function formatResendTags(array $tags, array $metadata): array
    {
        $result = [];

        foreach ($metadata as $key => $val) {
            $name = $this->sanitizeTagString((string) $key);
            $value = $this->sanitizeTagString((string) $val);

            if ($name !== '' && $value !== '') {
                $result[] = ['name' => $name, 'value' => $value];
            }
        }

        foreach ($tags as $tag) {
            $value = $this->sanitizeTagString((string) $tag);

            if ($value !== '') {
                $result[] = ['name' => 'tag', 'value' => $value];
            }
        }

        return $result;
    }

    /**
     * Sanitize string for Resend tag name/value ([a-zA-Z0-9_-], max 256 chars).
     */
    private function sanitizeTagString(string $input): string
    {
        $sanitized = (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', trim($input));
        $trimmed = trim($sanitized, '_');

        return mb_substr($trimmed, 0, 256);
    }

    public function __toString(): string
    {
        return 'resend';
    }
}
