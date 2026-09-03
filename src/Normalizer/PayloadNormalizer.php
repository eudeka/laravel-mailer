<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Normalizer;

use Eudeka\LaravelMailer\DTO\Address;
use Eudeka\LaravelMailer\DTO\EmailAttachment;
use Eudeka\LaravelMailer\DTO\NormalizedEmailPayload;
use Symfony\Component\Mime\Address as SymfonyAddress;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;

final readonly class PayloadNormalizer
{
    /**
     * Normalize a Symfony Email instance into an internal NormalizedEmailPayload DTO.
     */
    public function normalize(Email $email): NormalizedEmailPayload
    {
        $fromAddresses = $this->convertAddresses($email->getFrom());
        $from = $fromAddresses[0] ?? new Address(address: 'noreply@example.com');

        $to = $this->convertAddresses($email->getTo());
        $cc = $this->convertAddresses($email->getCc());
        $bcc = $this->convertAddresses($email->getBcc());
        $replyTo = $this->convertAddresses($email->getReplyTo());

        $subject = (string) $email->getSubject();
        $text = $email->getTextBody();
        $html = $email->getHtmlBody();

        $attachments = $this->extractAttachments($email);
        $headerData = $this->extractHeadersTagsAndMetadata($email);

        return new NormalizedEmailPayload(
            from: $from,
            to: $to,
            subject: $subject,
            html: is_string($html) ? $html : null,
            text: is_string($text) ? $text : null,
            cc: $cc,
            bcc: $bcc,
            replyTo: $replyTo,
            attachments: $attachments,
            headers: $headerData['headers'],
            tags: $headerData['tags'],
            metadata: $headerData['metadata'],
        );
    }

    /**
     * @param  array<SymfonyAddress>  $addresses
     * @return array<Address>
     */
    private function convertAddresses(array $addresses): array
    {
        $result = [];

        foreach ($addresses as $address) {
            $name = $address->getName();
            $result[] = new Address(
                address: $address->getAddress(),
                name: $name !== '' ? $name : null,
            );
        }

        return $result;
    }

    /**
     * Extract attachments and inline embedded images from the email.
     *
     * @return array<EmailAttachment>
     */
    private function extractAttachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $part) {
            $rawBody = $part->getBody();
            $filename = $part->getFilename() ?? $part->getName() ?? 'attachment';
            $mimeType = sprintf('%s/%s', $part->getMediaType(), $part->getMediaSubtype());
            $isInline = $part->getDisposition() === 'inline';

            $contentId = trim($part->getContentId(), '<>');

            $attachments[] = new EmailAttachment(
                filename: $filename,
                contentBase64: base64_encode($rawBody),
                mimeType: $mimeType,
                isInline: $isInline,
                contentId: $contentId !== '' ? $contentId : null,
            );
        }

        return $attachments;
    }

    /**
     * Extract custom headers, tags, and metadata from the email.
     *
     * @return array{headers: array<string, string>, tags: array<string>, metadata: array<string, string>}
     */
    private function extractHeadersTagsAndMetadata(Email $email): array
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

            // Check if header is a TagHeader class
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

            // Check if header is a MetadataHeader class
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

            // Check for Tag headers by convention (x-tag, x-tags, tag, tags)
            if (in_array($name, ['x-tag', 'x-tags', 'tag', 'tags'], true)) {
                $val = $header->getBodyAsString();
                foreach (array_map('trim', explode(',', $val)) as $tag) {
                    if ($tag !== '' && ! in_array($tag, $tags, true)) {
                        $tags[] = $tag;
                    }
                }

                continue;
            }

            // Check for individual metadata header: X-Metadata-{Key}: Value
            if (str_starts_with($name, 'x-metadata-')) {
                $metaKey = substr($header->getName(), strlen('x-metadata-'));
                $metadata[$metaKey] = $header->getBodyAsString();

                continue;
            }

            // Check for serialized JSON metadata header: X-Metadata: {"key":"val"}
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
