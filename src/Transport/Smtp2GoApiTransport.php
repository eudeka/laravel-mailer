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

final class Smtp2GoApiTransport extends AbstractTransport
{
    use ExtractsEmailData;

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly string $endpoint = 'https://api.smtp2go.com/v3/email/send',
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
            throw new TransportException('SMTP2GO API key is missing or not configured.');
        }

        $email = $this->extractEmail($message);

        $fromAddresses = $email->getFrom();
        $sender = isset($fromAddresses[0])
            ? $this->formatAddress($fromAddresses[0])
            : 'noreply@example.com';

        $to = array_map(fn (Address $addr): string => $this->formatAddress($addr), $email->getTo());

        if ($to === []) {
            throw new TransportException('SMTP2GO requires at least one "to" recipient.');
        }

        /** @var array<string, mixed> $payload */
        $payload = [
            'sender' => $sender,
            'to' => $to,
            'subject' => (string) $email->getSubject(),
        ];

        $html = $email->getHtmlBody();

        if (is_string($html) && $html !== '') {
            $payload['html_body'] = $html;
        }

        $text = $email->getTextBody();

        if (is_string($text) && $text !== '') {
            $payload['text_body'] = $text;
        }

        $cc = array_map(fn (Address $addr): string => $this->formatAddress($addr), $email->getCc());

        if ($cc !== []) {
            $payload['cc'] = $cc;
        }

        $bcc = array_map(fn (Address $addr): string => $this->formatAddress($addr), $email->getBcc());

        if ($bcc !== []) {
            $payload['bcc'] = $bcc;
        }

        $rawAttachments = $this->extractAttachments($email);

        if ($rawAttachments !== []) {
            $payload['attachments'] = array_map(
                fn (array $att): array => [
                    'filename' => $att['filename'],
                    'fileblob' => $att['content'],
                    'mimetype' => $att['contentType'],
                ],
                $rawAttachments,
            );
        }

        $extracted = $this->extractHeadersTagsAndMetadata($email);

        $customHeaders = [];
        foreach ($extracted['headers'] as $hName => $hVal) {
            $customHeaders[] = [
                'header' => $hName,
                'value' => $hVal,
            ];
        }

        if ($customHeaders !== []) {
            $payload['custom_headers'] = $customHeaders;
        }

        try {
            $response = Http::withHeaders([
                'api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->post($this->endpoint, $payload);
        } catch (Throwable $e) {
            throw new TransportException(sprintf('Failed sending email via SMTP2GO: %s', $e->getMessage()), 0, $e);
        }

        if (! $response->successful()) {
            $errorMsg = $response->json('data.error')
                ?? $response->json('message')
                ?? $response->body();

            throw new TransportException(sprintf(
                'Failed sending email via SMTP2GO (HTTP %d): %s',
                $response->status(),
                is_string($errorMsg) ? $errorMsg : json_encode($errorMsg),
            ));
        }

        $data = $response->json('data');

        if (is_array($data)) {
            $failed = $data['failed'] ?? 0;

            if (is_numeric($failed) && (int) $failed > 0) {
                $errorDetail = null;

                if (isset($data['error']) && is_string($data['error'])) {
                    $errorDetail = $data['error'];
                } elseif (isset($data['failures']) && is_array($data['failures']) && isset($data['failures'][0])) {
                    $firstFailure = $data['failures'][0];
                    $errorDetail = is_string($firstFailure) ? $firstFailure : json_encode($firstFailure);
                }

                $errorDetail ??= 'Provider reported delivery failure.';

                throw new TransportException(sprintf(
                    'Failed sending email via SMTP2GO: %s',
                    $errorDetail,
                ));
            }

            $emailId = $data['email_id'] ?? null;

            if (is_string($emailId) && $emailId !== '') {
                $message->setMessageId($emailId);
            }
        }

        $message->appendDebug('Sent via SMTP2GO API');
    }

    public function __toString(): string
    {
        return 'smtp2go';
    }
}
