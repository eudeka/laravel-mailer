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
        $headers = $this->extractCustomHeaders($email);

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
            headers: $headers,
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
     * Extract custom headers from the email, skipping standard envelope/mime headers.
     *
     * @return array<string, string>
     */
    private function extractCustomHeaders(Email $email): array
    {
        $excluded = [
            'from', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'date',
            'content-type', 'mime-version', 'message-id', 'content-transfer-encoding',
        ];

        $headers = [];

        /** @var HeaderInterface $header */
        foreach ($email->getHeaders()->all() as $header) {
            $name = strtolower($header->getName());

            if (! in_array($name, $excluded, true)) {
                $headers[$header->getName()] = $header->getBodyAsString();
            }
        }

        return $headers;
    }
}
