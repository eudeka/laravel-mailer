<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\DTO;

final readonly class Address
{
    public function __construct(
        public string $address,
        public ?string $name = null,
    ) {}

    /**
     * Format the address as "Name <email@example.com>" or "email@example.com".
     */
    public function format(): string
    {
        if ($this->name !== null && $this->name !== '') {
            return sprintf('%s <%s>', $this->name, $this->address);
        }

        return $this->address;
    }

    /**
     * Convert the address to an array representation.
     *
     * @return array{name: ?string, email: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->address,
        ];
    }
}
