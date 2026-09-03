<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Providers;

use Eudeka\LaravelMailer\DTO\Address;
use Eudeka\LaravelMailer\DTO\EmailAttachment;
use Eudeka\LaravelMailer\DTO\NormalizedEmailPayload;
use Eudeka\LaravelMailer\DTO\ProviderResponse;
use Illuminate\Http\Client\Response;

final class ResendProvider extends AbstractEmailProvider
{
    public function name(): string
    {
        return 'resend';
    }

    public function send(NormalizedEmailPayload $payload): ProviderResponse
    {
        if (! $this->hasCredentials()) {
            return ProviderResponse::failure(
                providerName: $this->name(),
                statusCode: 401,
                errorMessage: 'Resend API key is missing or not configured.',
            );
        }

        $headers = [
            'Authorization' => sprintf('Bearer %s', (string) $this->apiKey),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        /** @var array<string, mixed> $body */
        $body = [
            'from' => $payload->from->format(),
            'to' => array_map(fn (Address $addr): string => $addr->format(), $payload->to),
            'subject' => $payload->subject,
        ];

        if ($payload->html !== null && $payload->html !== '') {
            $body['html'] = $payload->html;
        }

        if ($payload->text !== null && $payload->text !== '') {
            $body['text'] = $payload->text;
        }

        if ($payload->cc !== []) {
            $body['cc'] = array_map(fn (Address $addr): string => $addr->format(), $payload->cc);
        }

        if ($payload->bcc !== []) {
            $body['bcc'] = array_map(fn (Address $addr): string => $addr->format(), $payload->bcc);
        }

        if ($payload->replyTo !== []) {
            $body['reply_to'] = array_map(fn (Address $addr): string => $addr->format(), $payload->replyTo);
        }

        if ($payload->headers !== []) {
            $body['headers'] = $payload->headers;
        }

        $tags = [];

        foreach ($payload->tags as $tag) {
            $tags[] = [
                'name' => 'tag',
                'value' => $this->sanitizeTag($tag),
            ];
        }

        foreach ($payload->metadata as $name => $value) {
            $tags[] = [
                'name' => $this->sanitizeTag($name),
                'value' => $this->sanitizeTag($value),
            ];
        }

        if ($tags !== []) {
            $body['tags'] = $tags;
        }

        if ($payload->attachments !== []) {
            $body['attachments'] = array_map(function (EmailAttachment $att): array {
                $attachment = [
                    'filename' => $att->filename,
                    'content' => $att->contentBase64,
                ];

                if ($att->isInline && $att->contentId !== null) {
                    $attachment['content_id'] = $att->contentId;
                }

                if ($att->mimeType !== '') {
                    $attachment['content_type'] = $att->mimeType;
                }

                return $attachment;
            }, $payload->attachments);
        }

        return $this->postJson($headers, $body);
    }

    /**
     * Sanitize tag name/value according to Resend API requirements (a-z, A-Z, 0-9, _, -).
     */
    private function sanitizeTag(string $value): string
    {
        $sanitized = (string) preg_replace('/[^a-zA-Z0-9_\-]/', '_', $value);
        $trimmed = trim($sanitized, '_-');

        return substr($trimmed !== '' ? $trimmed : 'default', 0, 256);
    }

    protected function handleHttpResponse(Response $response): ProviderResponse
    {
        $data = $response->json();
        /** @var array<string, mixed> $raw */
        $raw = is_array($data) ? $data : ['body' => $response->body()];

        if ($response->successful()) {
            $messageId = is_array($data) && isset($data['id']) && is_string($data['id']) ? $data['id'] : null;

            return ProviderResponse::success(
                providerName: $this->name(),
                statusCode: $response->status(),
                messageId: $messageId,
                rawResponse: $raw,
            );
        }

        $message = is_array($data) && isset($data['message']) && is_string($data['message'])
            ? $data['message']
            : $response->body();

        return ProviderResponse::failure(
            providerName: $this->name(),
            statusCode: $response->status(),
            errorMessage: sprintf('Resend API Error [%d]: %s', $response->status(), $message),
            rawResponse: $raw,
        );
    }
}
