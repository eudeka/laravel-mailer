<?php

declare(strict_types=1);

namespace EmailProvider\EmailProvider\DTO;

final readonly class EmailAttachment
{
    public function __construct(
        public string $filename,
        public string $contentBase64,
        public string $mimeType,
        public bool $isInline = false,
        public ?string $contentId = null,
    ) {}
}
