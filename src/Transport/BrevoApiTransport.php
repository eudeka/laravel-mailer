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

final class BrevoApiTransport extends AbstractTransport
{
    use ExtractsEmailData;

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly string $endpoint = 'https://api.brevo.com/v3/smtp/email',
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
            throw new TransportException('Brevo API key is missing or not configured.');
        }

        $email = $this->extractEmail($message);

        $fromAddresses = $email->getFrom();
        $sender = isset($fromAddresses[0])
            ? $this->addressToArray($fromAddresses[0], 70)
            : ['email' => 'noreply@example.com'];

        $to = array_map(fn (Address $addr): array => $this->addressToArray($addr, 70), $email->getTo());

        if ($to === []) {
            throw new TransportException('Brevo requires at least one "to" recipient.');
        }

        /** @var array<string, mixed> $payload */
        $payload = [
            'sender' => $sender,
            'to' => $to,
            'subject' => (string) $email->getSubject(),
        ];

        $rawAttachments = $this->extractAttachments($email);

        $html = $email->getHtmlBody();

        if (is_string($html) && $html !== '') {
            foreach ($rawAttachments as $att) {
                if ($att['isInline'] || $att['contentId'] !== null) {
                    $dataUri = sprintf('data:%s;base64,%s', $att['contentType'], $att['content']);

                    if ($att['contentId'] !== null && $att['contentId'] !== '') {
                        $html = str_replace('cid:'.$att['contentId'], $dataUri, $html);
                    }

                    if ($att['filename'] !== '') {
                        $html = str_replace('cid:'.$att['filename'], $dataUri, $html);
                    }
                }
            }

            $payload['htmlContent'] = $html;
        }

        $text = $this->resolvePlainTextBody($email->getTextBody(), $html);

        if (is_string($text) && $text !== '') {
            $payload['textContent'] = $text;
        }

        $cc = array_map(fn (Address $addr): array => $this->addressToArray($addr, 70), $email->getCc());

        if ($cc !== []) {
            $payload['cc'] = $cc;
        }

        $bcc = array_map(fn (Address $addr): array => $this->addressToArray($addr, 70), $email->getBcc());

        if ($bcc !== []) {
            $payload['bcc'] = $bcc;
        }

        $replyTo = $email->getReplyTo();

        if (isset($replyTo[0])) {
            $payload['replyTo'] = $this->addressToArray($replyTo[0], 70);
        }

        if ($rawAttachments !== []) {
            $payload['attachment'] = array_map(
                fn (array $att): array => [
                    'name' => $att['filename'],
                    'content' => $att['content'],
                ],
                $rawAttachments,
            );
        }

        $extracted = $this->extractHeadersTagsAndMetadata($email);
        $headers = $extracted['headers'];
        $metadata = $extracted['metadata'];

        if ($metadata !== []) {
            $payload['params'] = $metadata;

            foreach ($metadata as $metaKey => $metaVal) {
                $headerKey = 'X-Metadata-'.str_replace(' ', '-', ucwords(str_replace(['-', '_'], ' ', (string) $metaKey)));

                if (! isset($headers[$headerKey])) {
                    $headers[$headerKey] = (string) $metaVal;
                }
            }
        }

        $senderEmail = $sender['email'];
        $toEmails = array_map(fn (array $addr): string => $addr['email'], $to);
        $idempotencyKey = $this->resolveIdempotencyKey($headers, $email, $senderEmail, $toEmails);
        $headers = $this->removeHeaderCaseInsensitive($headers, ['idempotency-key', 'x-idempotency-key']);
        $headers['Idempotency-Key'] = $idempotencyKey;

        $payload['headers'] = $headers;

        if ($extracted['tags'] !== []) {
            $payload['tags'] = $extracted['tags'];
        }

        try {
            $response = Http::withHeaders([
                'api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'eudeka-laravel-mailer/1.0',
            ])
                ->timeout($this->timeout)
                ->post($this->endpoint, $payload);
        } catch (Throwable $e) {
            throw new TransportException(sprintf('Failed sending email via Brevo: %s', $e->getMessage()), 0, $e);
        }

        if (! $response->successful()) {
            $code = $response->json('code');
            $rawMsg = $response->json('message') ?? $response->body();
            $msgText = is_string($rawMsg) ? $rawMsg : (json_encode($rawMsg) ?: 'Unknown error');

            $errorMsg = is_string($code) && $code !== ''
                ? sprintf('code: %s, message: %s', $code, $msgText)
                : $msgText;

            $errorMsg = $this->formatRetryAfterError(
                $errorMsg,
                $response->header('Retry-After'),
                $response->status(),
            );

            throw new TransportException(sprintf(
                'Failed sending email via Brevo (HTTP %d): %s',
                $response->status(),
                $errorMsg,
            ));
        }

        $code = $response->json('code');
        $error = $response->json('error');
        $failed = $response->json('failed');

        if (is_string($code) && $code !== '') {
            $rawMsg = $response->json('message') ?? 'Unknown error';

            throw new TransportException(sprintf(
                'Failed sending email via Brevo: code: %s, message: %s',
                $code,
                is_string($rawMsg) ? $rawMsg : json_encode($rawMsg),
            ));
        }

        if (is_string($error) && $error !== '') {
            throw new TransportException(sprintf('Failed sending email via Brevo: %s', $error));
        }

        if (is_numeric($failed) && (int) $failed > 0) {
            throw new TransportException('Failed sending email via Brevo: Provider reported delivery failure.');
        }

        $messageId = $response->json('messageId') ?? $response->json('messageIds.0');

        if (! is_string($messageId) || trim($messageId) === '') {
            throw new TransportException('Failed sending email via Brevo: Provider returned 2xx response but did not return a valid messageId (email was not accepted).');
        }

        $message->setMessageId($messageId);

        $message->appendDebug('Sent via Brevo API');
    }

    public function __toString(): string
    {
        return 'brevo';
    }
}
