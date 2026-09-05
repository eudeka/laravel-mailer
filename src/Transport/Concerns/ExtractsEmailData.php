<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Transport\Concerns;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address as SymfonyAddress;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

trait ExtractsEmailData
{
    /**
     * Resolve a Symfony Email instance from a SentMessage.
     */
    protected function extractEmail(SentMessage $message): Email
    {
        $original = $message->getOriginalMessage();

        return match (true) {
            $original instanceof Email => $original,
            $original instanceof Message => MessageConverter::toEmail($original),
            default => new Email,
        };
    }

    /**
     * Format a Symfony Address into RFC-5322 compliant "Name <email>" or "email".
     */
    protected function formatAddress(SymfonyAddress $address): string
    {
        return $address->toString();
    }

    /**
     * Convert a Symfony Address to an associative array for API payloads.
     *
     * @return array{email: string, name?: string}
     */
    protected function addressToArray(SymfonyAddress $address, ?int $maxNameLength = null): array
    {
        $data = ['email' => $address->getAddress()];
        $name = trim($address->getName());

        if ($name !== '') {
            $data['name'] = $maxNameLength !== null && $maxNameLength > 0
                ? mb_substr($name, 0, $maxNameLength)
                : $name;
        }

        return $data;
    }

    /**
     * Extract attachments and inline embedded files from the email.
     *
     * @return array<int, array{filename: string, content: string, contentType: string, isInline: bool, contentId: ?string}>
     */
    protected function extractAttachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $part) {
            $rawBody = $part->getBody();
            $filename = $part->getFilename() ?? $part->getName() ?? 'attachment';
            $mimeType = sprintf('%s/%s', $part->getMediaType(), $part->getMediaSubtype());
            $isInline = $part->getDisposition() === 'inline';
            $contentId = trim($part->getContentId(), '<>');

            $attachments[] = [
                'filename' => $filename,
                'content' => base64_encode($rawBody),
                'contentType' => $mimeType,
                'isInline' => $isInline,
                'contentId' => $contentId !== '' ? $contentId : null,
            ];
        }

        return $attachments;
    }

    /**
     * Extract custom headers, tags, and metadata from the email.
     *
     * @return array{headers: array<string, string>, tags: array<string>, metadata: array<string, string>}
     */
    protected function extractHeadersTagsAndMetadata(Email $email): array
    {
        $excluded = [
            'from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'date',
            'content-type', 'mime-version', 'message-id', 'content-transfer-encoding',
            'return-path', 'sender', 'received', 'dkim-signature', 'comments', 'keywords',
            'references', 'in-reply-to', 'auto-submitted',
        ];

        $headers = [];
        $tags = [];
        $metadata = [];

        /** @var HeaderInterface $header */
        foreach ($email->getHeaders()->all() as $header) {
            $name = strtolower($header->getName());

            if (in_array($name, $excluded, true)) {
                continue;
            }

            if (str_ends_with(get_class($header), 'TagHeader')) {
                $rawVal = method_exists($header, 'getValue') ? $header->getValue() : null;
                $val = is_scalar($rawVal) ? (string) $rawVal : $header->getBodyAsString();
                foreach (array_map('trim', explode(',', $val)) as $tag) {
                    if ($tag !== '' && ! in_array($tag, $tags, true)) {
                        $tags[] = $tag;
                    }
                }

                continue;
            }

            if (str_ends_with(get_class($header), 'MetadataHeader')) {
                $rawKey = method_exists($header, 'getKey') ? $header->getKey() : null;
                $rawVal = method_exists($header, 'getValue') ? $header->getValue() : null;

                if (is_scalar($rawKey) && is_scalar($rawVal)) {
                    $metadata[(string) $rawKey] = (string) $rawVal;
                } else {
                    $val = $header->getBodyAsString();
                    $parts = explode('=', $val, 2);

                    if (count($parts) === 2) {
                        $metadata[trim($parts[0])] = trim($parts[1]);
                    }
                }

                continue;
            }

            if (in_array($name, ['x-tag', 'x-tags', 'tag', 'tags'], true)) {
                $val = $header->getBodyAsString();
                foreach (array_map('trim', explode(',', $val)) as $tag) {
                    if ($tag !== '' && ! in_array($tag, $tags, true)) {
                        $tags[] = $tag;
                    }
                }

                continue;
            }

            if (str_starts_with($name, 'x-metadata-')) {
                $metaKey = substr($header->getName(), strlen('x-metadata-'));
                $metadata[$metaKey] = $header->getBodyAsString();

                continue;
            }

            if ($name === 'x-metadata' || $name === 'metadata') {
                $val = $header->getBodyAsString();
                $decoded = json_decode($val, true);

                if (is_array($decoded)) {
                    foreach ($decoded as $k => $v) {
                        if (is_scalar($v)) {
                            $metadata[(string) $k] = (string) $v;
                        }
                    }
                }

                continue;
            }

            $headers[$header->getName()] = $header->getBodyAsString();
        }

        return [
            'headers' => $headers,
            'tags' => $tags,
            'metadata' => $metadata,
        ];
    }

    /**
     * Resolve plain text body, falling back to stripping tags from HTML if plain text is empty.
     */
    protected function resolvePlainTextBody(mixed $text, mixed $html): ?string
    {
        $textString = is_resource($text) ? stream_get_contents($text) : $text;

        if (is_string($textString) && trim($textString) !== '') {
            return $textString;
        }

        $htmlString = is_resource($html) ? stream_get_contents($html) : $html;

        if (is_string($htmlString) && trim($htmlString) !== '') {
            $plain = trim(strip_tags($htmlString));

            if ($plain !== '') {
                return $plain;
            }
        }

        return null;
    }

    /**
     * Resolve the idempotency key from email headers or auto-generate one.
     *
     * @param  array<string, string>  $headers
     * @param  array<string>  $to
     */
    protected function resolveIdempotencyKey(array $headers, Email $email, string $from, array $to): string
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
    protected function sanitizeIdempotencyKey(string $key): string
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
    protected function extractHeaderValue(array $headers, array $names): ?string
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
    protected function removeHeaderCaseInsensitive(array $headers, array $names): array
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
     * Format error message with Retry-After details on HTTP 429 rate limit.
     */
    protected function formatRetryAfterError(string $errorMessage, ?string $retryAfter, int $status): string
    {
        if ($status === 429 && is_string($retryAfter) && trim($retryAfter) !== '') {
            return $errorMessage.sprintf(' (retry after %ss)', trim($retryAfter));
        }

        return $errorMessage;
    }
}
