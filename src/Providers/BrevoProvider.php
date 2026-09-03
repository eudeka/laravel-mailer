<?php

declare(strict_types=1);

namespace EmailProvider\EmailProvider\Providers;

use EmailProvider\EmailProvider\DTO\Address;
use EmailProvider\EmailProvider\DTO\EmailAttachment;
use EmailProvider\EmailProvider\DTO\NormalizedEmailPayload;
use EmailProvider\EmailProvider\DTO\ProviderResponse;
use Illuminate\Http\Client\Response;

final class BrevoProvider extends AbstractEmailProvider
{
    public function name(): string
    {
        return 'brevo';
    }

    public function send(NormalizedEmailPayload $payload): ProviderResponse
    {
        if (! $this->hasCredentials()) {
            return ProviderResponse::failure(
                providerName: $this->name(),
                statusCode: 401,
                errorMessage: 'Brevo API key is missing or not configured.',
            );
        }

        $headers = [
            'api-key' => (string) $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        /** @var array<string, mixed> $body */
        $body = [
            'sender' => array_filter([
                'email' => $payload->from->address,
                'name' => $payload->from->name,
            ], fn (mixed $val): bool => $val !== null && $val !== ''),
            'to' => array_map(fn (Address $addr): array => array_filter([
                'email' => $addr->address,
                'name' => $addr->name,
            ], fn (mixed $val): bool => $val !== null && $val !== ''), $payload->to),
            'subject' => $payload->subject,
        ];

        if ($payload->html !== null && $payload->html !== '') {
            $body['htmlContent'] = $payload->html;
        }

        if ($payload->text !== null && $payload->text !== '') {
            $body['textContent'] = $payload->text;
        }

        if ($payload->cc !== []) {
            $body['cc'] = array_map(fn (Address $addr): array => array_filter([
                'email' => $addr->address,
                'name' => $addr->name,
            ], fn (mixed $val): bool => $val !== null && $val !== ''), $payload->cc);
        }

        if ($payload->bcc !== []) {
            $body['bcc'] = array_map(fn (Address $addr): array => [
                'email' => $addr->address,
            ], $payload->bcc);
        }

        if ($payload->replyTo !== []) {
            $firstReplyTo = $payload->replyTo[0];
            $body['replyTo'] = array_filter([
                'email' => $firstReplyTo->address,
                'name' => $firstReplyTo->name,
            ], fn (mixed $val): bool => $val !== null && $val !== '');
        }

        if ($payload->headers !== []) {
            $body['headers'] = $payload->headers;
        }

        if ($payload->attachments !== []) {
            $body['attachment'] = array_map(fn (EmailAttachment $att): array => [
                'name' => $att->filename,
                'content' => $att->contentBase64,
            ], $payload->attachments);
        }

        return $this->postJson($headers, $body);
    }

    protected function handleHttpResponse(Response $response): ProviderResponse
    {
        $data = $response->json();
        $raw = is_array($data) ? $data : ['body' => $response->body()];

        if ($response->successful()) {
            $messageId = is_array($data) && isset($data['messageId']) && is_string($data['messageId'])
                ? $data['messageId']
                : null;

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
            errorMessage: sprintf('Brevo API Error [%d]: %s', $response->status(), $message),
            rawResponse: $raw,
        );
    }
}
