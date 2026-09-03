<?php

declare(strict_types=1);

namespace EmailProvider\EmailProvider\DTO;

final readonly class NormalizedEmailPayload
{
    /**
     * @param  array<Address>  $to
     * @param  array<Address>  $cc
     * @param  array<Address>  $bcc
     * @param  array<Address>  $replyTo
     * @param  array<EmailAttachment>  $attachments
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public Address $from,
        public array $to,
        public string $subject,
        public ?string $html = null,
        public ?string $text = null,
        public array $cc = [],
        public array $bcc = [],
        public array $replyTo = [],
        public array $attachments = [],
        public array $headers = [],
    ) {}
}
