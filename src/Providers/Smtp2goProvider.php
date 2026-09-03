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
        $raw = is_array($data) ? $data : ['body' => $response->body()];

        if ($response->successful()) {
            if (is_array($data) && isset($data['data']['failed']) && (int) $data['data']['failed'] > 0) {
                $errorMsg = 'SMTP2GO reported recipient delivery failure.';

                if (isset($data['data']['failures'][0]) && is_string($data['data']['failures'][0])) {
                    $errorMsg = $data['data']['failures'][0];
                }

                return ProviderResponse::failure(
                    providerName: $this->name(),
                    statusCode: $response->status(),
                    errorMessage: sprintf('SMTP2GO API Error: %s', $errorMsg),
                    rawResponse: $raw,
                );
            }

            if (is_array($data) && isset($data['data']['error']) && is_string($data['data']['error'])) {
                return ProviderResponse::failure(
                    providerName: $this->name(),
                    statusCode: $response->status(),
                    errorMessage: sprintf('SMTP2GO API Error: %s', $data['data']['error']),
                    rawResponse: $raw,
                );
            }

            $messageId = null;

            if (is_array($data) && isset($data['data']['email_id']) && is_string($data['data']['email_id'])) {
                $messageId = $data['data']['email_id'];
            }

            return ProviderResponse::success(
                providerName: $this->name(),
                statusCode: $response->status(),
                messageId: $messageId,
                rawResponse: $raw,
            );
        }

        $message = $response->body();

        if (is_array($data) && isset($data['data']['error']) && is_string($data['data']['error'])) {
            $message = $data['data']['error'];
        }

        return ProviderResponse::failure(
            providerName: $this->name(),
            statusCode: $response->status(),
            errorMessage: sprintf('SMTP2GO API Error [%d]: %s', $response->status(), $message),
            rawResponse: $raw,
        );
    }
}
