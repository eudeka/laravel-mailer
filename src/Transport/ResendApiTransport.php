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
                fn (array $att): array => [
                    'filename' => $att['filename'],
                    'content' => $att['content'],
                ],
                $rawAttachments,
            );
        }

        $extracted = $this->extractHeadersTagsAndMetadata($email);

        if ($extracted['headers'] !== []) {
            $payload['headers'] = $extracted['headers'];
        }

        if ($extracted['tags'] !== []) {
            $payload['tags'] = array_map(
                fn (string $tag): array => ['name' => 'tag', 'value' => $tag],
                $extracted['tags'],
            );
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->timeout($this->timeout)
                ->post($this->endpoint, $payload);
        } catch (Throwable $e) {
            throw new TransportException(sprintf('Failed sending email via Resend: %s', $e->getMessage()), 0, $e);
        }

        if (! $response->successful()) {
            $errorMsg = $response->json('message') ?? $response->body();

            throw new TransportException(sprintf(
                'Failed sending email via Resend (HTTP %d): %s',
                $response->status(),
                is_string($errorMsg) ? $errorMsg : json_encode($errorMsg),
            ));
        }

        $messageId = $response->json('id');

        if (is_string($messageId) && $messageId !== '') {
            $message->setMessageId($messageId);
        }

        $message->appendDebug('Sent via Resend API');
    }

    public function __toString(): string
    {
        return 'resend';
    }
}
