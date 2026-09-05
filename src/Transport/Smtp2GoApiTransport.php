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
use Symfony\Component\Mime\Email;
use Throwable;

final class Smtp2GoApiTransport extends AbstractTransport
{
    use ExtractsEmailData;

    private readonly string $endpoint;

    public function __construct(
        private readonly ?string $apiKey = null,
        ?string $endpoint = null,
        private readonly int $timeout = 10,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger);

        $this->endpoint = is_string($endpoint) && trim($endpoint) !== ''
            ? trim($endpoint)
            : 'https://api.smtp2go.com/v3/email/send';
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
            'fastaccept' => false,
        ];

        $html = $email->getHtmlBody();
        $text = $this->resolvePlainTextBody($email->getTextBody(), $html);

        if (is_string($html) && $html !== '') {
            $payload['html_body'] = $html;
        }

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
        $attachments = [];
        $inlines = [];

        foreach ($rawAttachments as $att) {
            if ($att['isInline']) {
                $inlineFilename = $att['filename'];

                if ($att['contentId'] !== null) {
                    if (is_string($html) && str_contains($html, 'cid:'.$att['contentId'])) {
                        $inlineFilename = $att['contentId'];
                    } elseif (is_string($html) && str_contains($html, 'cid:'.$att['filename'])) {
                        $inlineFilename = $att['filename'];
                    } else {
                        $inlineFilename = $att['contentId'];
                    }
                }

                $inlines[] = [
                    'filename' => $inlineFilename,
                    'fileblob' => $att['content'],
                    'mimetype' => $att['contentType'],
                ];
            } else {
                $attachments[] = [
                    'filename' => $att['filename'],
                    'fileblob' => $att['content'],
                    'mimetype' => $att['contentType'],
                ];
            }
        }

        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }

        if ($inlines !== []) {
            $payload['inlines'] = $inlines;
        }

        $customHeaders = $this->buildCustomHeaders($email);

        if ($customHeaders !== []) {
            $payload['custom_headers'] = $customHeaders;
        }

        $extracted = $this->extractHeadersTagsAndMetadata($email);
        $idempotencyKey = $this->resolveIdempotencyKey($extracted['headers'], $email, $sender, $to);

        try {
            $response = Http::withHeaders([
                'X-Smtp2go-Api-Key' => $this->apiKey,
                'api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'eudeka-laravel-mailer/1.0',
                'Idempotency-Key' => $idempotencyKey,
            ])
                ->timeout($this->timeout)
                ->post($this->endpoint, $payload);
        } catch (Throwable $e) {
            throw new TransportException(sprintf('Failed sending email via SMTP2GO: %s', $e->getMessage()), 0, $e);
        }

        if (! $response->successful()) {
            $errorCode = $response->json('data.error_code');
            $errorMsg = $response->json('data.error')
                ?? $response->json('message')
                ?? $response->body();

            $formattedError = is_string($errorMsg) ? $errorMsg : (json_encode($errorMsg) ?: 'Unknown error');

            if (is_string($errorCode) && $errorCode !== '') {
                $formattedError = sprintf('[%s] %s', $errorCode, $formattedError);
            }

            $formattedError = $this->formatRetryAfterError(
                $formattedError,
                $response->header('Retry-After'),
                $response->status(),
            );

            throw new TransportException(sprintf(
                'Failed sending email via SMTP2GO (HTTP %d): %s',
                $response->status(),
                $formattedError,
            ));
        }

        $responseData = $response->json();

        if (! is_array($responseData)) {
            throw new TransportException('Failed sending email via SMTP2GO: Empty or invalid JSON response.');
        }

        if (isset($responseData['error']) && is_string($responseData['error'])) {
            $formattedError = $responseData['error'];

            if (isset($responseData['error_code']) && is_string($responseData['error_code']) && $responseData['error_code'] !== '') {
                $formattedError = sprintf('[%s] %s', $responseData['error_code'], $formattedError);
            }

            throw new TransportException(sprintf('Failed sending email via SMTP2GO: %s', $formattedError));
        }

        $data = $responseData['data'] ?? null;

        if (! is_array($data)) {
            throw new TransportException('Failed sending email via SMTP2GO: Missing data payload in provider response.');
        }

        if (isset($data['error']) && is_string($data['error'])) {
            $formattedError = $data['error'];

            if (isset($data['error_code']) && is_string($data['error_code']) && $data['error_code'] !== '') {
                $formattedError = sprintf('[%s] %s', $data['error_code'], $formattedError);
            }

            throw new TransportException(sprintf('Failed sending email via SMTP2GO: %s', $formattedError));
        }

        $failed = isset($data['failed']) && is_numeric($data['failed']) ? (int) $data['failed'] : 0;
        $succeeded = isset($data['succeeded']) && is_numeric($data['succeeded']) ? (int) $data['succeeded'] : 0;
        $failures = (isset($data['failures']) && is_array($data['failures'])) ? $data['failures'] : [];
        $emailId = isset($data['email_id']) && is_string($data['email_id']) ? trim($data['email_id']) : '';

        if ($failed > 0 || $succeeded === 0 || $failures !== [] || $emailId === '') {
            $errorDetail = null;

            if ($failures !== []) {
                $failuresList = array_map(
                    fn (mixed $f): string => is_string($f) ? $f : (string) json_encode($f),
                    $failures,
                );
                $errorDetail = implode('; ', $failuresList);
            } elseif ($failed > 0 && $succeeded === 0) {
                $errorDetail = sprintf('Provider reported delivery failure (%d failed, 0 succeeded).', $failed);
            } elseif ($failed > 0) {
                $errorDetail = sprintf('Provider reported partial delivery failure (%d failed, %d succeeded).', $failed, $succeeded);
            } elseif ($succeeded === 0) {
                $errorDetail = 'Provider reported that 0 emails were delivered.';
            } elseif ($emailId === '') {
                $errorDetail = 'Provider did not return an email_id indicating delivery acceptance.';
            }

            $errorDetail ??= 'Provider reported delivery failure.';

            if (isset($data['error_code']) && is_string($data['error_code']) && $data['error_code'] !== '') {
                $errorDetail = sprintf('[%s] %s', $data['error_code'], $errorDetail);
            }

            throw new TransportException(sprintf(
                'Failed sending email via SMTP2GO: %s',
                $errorDetail,
            ));
        }

        $message->setMessageId($emailId);
        $message->appendDebug('Sent via SMTP2GO API');
    }

    /**
     * @return array<int, array{header: string, value: string}>
     */
    private function buildCustomHeaders(Email $email): array
    {
        $customHeaders = [];

        $replyToAddresses = array_map(
            fn (Address $addr): string => $this->formatAddress($addr),
            $email->getReplyTo(),
        );

        if ($replyToAddresses !== []) {
            $customHeaders[] = [
                'header' => 'Reply-To',
                'value' => implode(', ', $replyToAddresses),
            ];
        }

        $headers = $email->getHeaders();
        foreach (['In-Reply-To', 'References'] as $threadHeader) {
            if ($headers->has($threadHeader)) {
                $headerObj = $headers->get($threadHeader);

                if ($headerObj !== null) {
                    $customHeaders[] = [
                        'header' => $threadHeader,
                        'value' => $headerObj->getBodyAsString(),
                    ];
                }
            }
        }

        $extracted = $this->extractHeadersTagsAndMetadata($email);

        $forbiddenHeaders = [
            'content-type',
            'content-transfer-encoding',
            'mime-version',
            'from',
            'to',
            'cc',
            'bcc',
            'subject',
            'reply-to',
            'in-reply-to',
            'references',
        ];

        foreach ($extracted['headers'] as $hName => $hVal) {
            if (in_array(strtolower($hName), $forbiddenHeaders, true)) {
                continue;
            }

            $customHeaders[] = [
                'header' => $hName,
                'value' => $hVal,
            ];
        }

        foreach ($extracted['tags'] as $tag) {
            $customHeaders[] = [
                'header' => 'X-Tag',
                'value' => $tag,
            ];
        }

        foreach ($extracted['metadata'] as $metaKey => $metaVal) {
            $customHeaders[] = [
                'header' => sprintf('X-Metadata-%s', $metaKey),
                'value' => $metaVal,
            ];
        }

        return $customHeaders;
    }

    public function __toString(): string
    {
        return 'smtp2go';
    }
}
