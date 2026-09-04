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
     * Format a Symfony Address into "Name <email>" or "email".
     */
    protected function formatAddress(SymfonyAddress $address): string
    {
        $name = trim($address->getName());

        if ($name !== '') {
            return sprintf('%s <%s>', $name, $address->getAddress());
        }

        return $address->getAddress();
    }

    /**
     * Convert a Symfony Address to an associative array for API payloads.
     *
     * @return array{email: string, name?: string}
     */
    protected function addressToArray(SymfonyAddress $address): array
    {
        $data = ['email' => $address->getAddress()];
        $name = trim($address->getName());

        if ($name !== '') {
            $data['name'] = $name;
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
}
