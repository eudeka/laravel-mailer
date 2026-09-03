<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Providers;

use Eudeka\LaravelMailer\DTO\Address;
use Eudeka\LaravelMailer\DTO\NormalizedEmailPayload;
use Eudeka\LaravelMailer\DTO\ProviderResponse;
use Illuminate\Http\Client\Response;

final class Smtp2goProvider extends AbstractEmailProvider
{
    public function name(): string
    {
        return 'smtp2go';
    }

    public function send(NormalizedEmailPayload $payload): ProviderResponse
    {
        if (! $this->hasCredentials()) {
            return ProviderResponse::failure(
                providerName: $this->name(),
                statusCode: 401,
                errorMessage: 'SMTP2GO API key is missing or not configured.',
            );
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        /** @var array<string, mixed> $body */
        $body = [
            'api_key' => (string) $this->apiKey,
            'sender' => $payload->from->format(),
            'to' => array_map(fn (Address $addr): string => $addr->format(), $payload->to),
            'subject' => $payload->subject,
        ];

        if ($payload->html !== null && $payload->html !== '') {
            $body['html_body'] = $payload->html;
        }

        if ($payload->text !== null && $payload->text !== '') {
            $body['text_body'] = $payload->text;
        }

        if ($payload->cc !== []) {
            $body['cc'] = array_map(fn (Address $addr): string => $addr->format(), $payload->cc);
        }

        if ($payload->bcc !== []) {
            $body['bcc'] = array_map(fn (Address $addr): string => $addr->format(), $payload->bcc);
        }

        $customHeaders = [];

        if ($payload->replyTo !== []) {
            $replyToValues = array_map(fn (Address $addr): string => $addr->format(), $payload->replyTo);
            $customHeaders[] = [
                'header' => 'Reply-To',
                'value' => implode(', ', $replyToValues),
            ];
        }

        foreach ($payload->headers as $headerName => $headerValue) {
            $customHeaders[] = [
                'header' => $headerName,
                'value' => $headerValue,
            ];
        }

        if ($payload->tags !== []) {
            $customHeaders[] = [
                'header' => 'X-Tag',
                'value' => implode(', ', $payload->tags),
            ];
        }

        foreach ($payload->metadata as $metaKey => $metaValue) {
            $customHeaders[] = [
                'header' => 'X-Metadata-'.$metaKey,
                'value' => $metaValue,
            ];
        }

        if ($customHeaders !== []) {
            $body['custom_headers'] = $customHeaders;
        }

        $attachments = [];
        $inlines = [];

        foreach ($payload->attachments as $attachment) {
            if ($attachment->isInline && $attachment->contentId !== null) {
                $inlines[] = [
                    'filename' => $attachment->filename,
                    'fileblob' => $attachment->contentBase64,
                    'mimetype' => $attachment->mimeType,
                    'cid' => $attachment->contentId,
                ];
            } else {
                $attachments[] = [
                    'filename' => $attachment->filename,
                    'fileblob' => $attachment->contentBase64,
                    'mimetype' => $attachment->mimeType,
                ];
            }
        }

        if ($attachments !== []) {
            $body['attachments'] = $attachments;
        }

        if ($inlines !== []) {
            $body['inlines'] = $inlines;
        }

        return $this->postJson($headers, $body);
    }

    protected function handleHttpResponse(Response $response): ProviderResponse
    {
        $data = $response->json();
        /** @var array<string, mixed> $raw */
        $raw = is_array($data) ? $data : ['body' => $response->body()];

        /** @var array<string, mixed>|null $responseData */
        $responseData = is_array($data) && isset($data['data']) && is_array($data['data'])
            ? $data['data']
            : null;

        if ($response->successful()) {
            $failedCount = $responseData !== null && isset($responseData['failed']) && is_numeric($responseData['failed'])
                ? (int) $responseData['failed']
                : 0;

            if ($failedCount > 0) {
                $errorMsg = 'SMTP2GO reported recipient delivery failure.';

                if (
                    isset($responseData['failures'])
                    && is_array($responseData['failures'])
                    && isset($responseData['failures'][0])
                    && is_string($responseData['failures'][0])
                ) {
                    $errorMsg = $responseData['failures'][0];
                }

                return ProviderResponse::failure(
                    providerName: $this->name(),
                    statusCode: $response->status(),
                    errorMessage: sprintf('SMTP2GO API Error: %s', $errorMsg),
                    rawResponse: $raw,
                );
            }

            if ($responseData !== null && isset($responseData['error']) && is_string($responseData['error'])) {
                return ProviderResponse::failure(
                    providerName: $this->name(),
                    statusCode: $response->status(),
                    errorMessage: sprintf('SMTP2GO API Error: %s', $responseData['error']),
                    rawResponse: $raw,
                );
            }

            $messageId = null;

            if ($responseData !== null && isset($responseData['email_id']) && is_string($responseData['email_id'])) {
                $messageId = $responseData['email_id'];
            }

            return ProviderResponse::success(
                providerName: $this->name(),
                statusCode: $response->status(),
                messageId: $messageId,
                rawResponse: $raw,
            );
        }

        $message = $response->body();

        if ($responseData !== null && isset($responseData['error']) && is_string($responseData['error'])) {
            $message = $responseData['error'];
        }

        return ProviderResponse::failure(
            providerName: $this->name(),
            statusCode: $response->status(),
            errorMessage: sprintf('SMTP2GO API Error [%d]: %s', $response->status(), $message),
            rawResponse: $raw,
        );
    }
}
