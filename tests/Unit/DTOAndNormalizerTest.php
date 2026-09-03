<?php

declare(strict_types=1);

use Eudeka\LaravelMailer\DTO\Address;
use Eudeka\LaravelMailer\DTO\NormalizedEmailPayload;
use Eudeka\LaravelMailer\DTO\ProviderResponse;
use Eudeka\LaravelMailer\Normalizer\PayloadNormalizer;
use Symfony\Component\Mime\Email;

it('formats address DTO correctly', function () {
    $named = new Address('john@example.com', 'John Doe');
    expect($named->format())->toBe('John Doe <john@example.com>')
        ->and($named->toArray())->toBe(['name' => 'John Doe', 'email' => 'john@example.com']);

    $unnamed = new Address('jane@example.com');
    expect($unnamed->format())->toBe('jane@example.com')
        ->and($unnamed->toArray())->toBe(['name' => null, 'email' => 'jane@example.com']);
});

it('creates provider response DTO correctly', function () {
    $success = ProviderResponse::success('resend', 200, 'msg-123', ['key' => 'val']);
    expect($success->isSuccessful)->toBeTrue()
        ->and($success->providerName)->toBe('resend')
        ->and($success->statusCode)->toBe(200)
        ->and($success->messageId)->toBe('msg-123')
        ->and($success->rawResponse)->toBe(['key' => 'val'])
        ->and($success->errorMessage)->toBeNull();

    $failure = ProviderResponse::failure('brevo', 429, 'Rate limit exceeded', ['error' => 'quota']);
    expect($failure->isSuccessful)->toBeFalse()
        ->and($failure->providerName)->toBe('brevo')
        ->and($failure->statusCode)->toBe(429)
        ->and($failure->messageId)->toBeNull()
        ->and($failure->errorMessage)->toBe('Rate limit exceeded');
});

it('normalizes a complete Symfony Email instance', function () {
    $email = new Email;
    $email->from('sender@example.com')
        ->to('recipient1@example.com', 'recipient2@example.com')
        ->cc('cc@example.com')
        ->bcc('bcc@example.com')
        ->replyTo('reply@example.com')
        ->subject('Test Subject')
        ->text('Plain text content')
        ->html('<p>HTML content</p>')
        ->attach('sample file content', 'document.txt', 'text/plain')
        ->embed('inline image content', 'logo.png', 'image/png');

    $email->getHeaders()->addTextHeader('X-Custom-Tracking', 'track-999');

    $normalizer = new PayloadNormalizer;
    $payload = $normalizer->normalize($email);

    expect($payload)->toBeInstanceOf(NormalizedEmailPayload::class)
        ->and($payload->from->address)->toBe('sender@example.com')
        ->and($payload->to)->toHaveCount(2)
        ->and($payload->cc)->toHaveCount(1)
        ->and($payload->bcc)->toHaveCount(1)
        ->and($payload->replyTo)->toHaveCount(1)
        ->and($payload->subject)->toBe('Test Subject')
        ->and($payload->text)->toBe('Plain text content')
        ->and($payload->html)->toBe('<p>HTML content</p>')
        ->and($payload->attachments)->toHaveCount(2)
        ->and($payload->headers)->toHaveKey('X-Custom-Tracking', 'track-999');

    $regularAtt = $payload->attachments[0];
    expect($regularAtt->filename)->toBe('document.txt')
        ->and($regularAtt->mimeType)->toBe('text/plain')
        ->and($regularAtt->isInline)->toBeFalse()
        ->and(base64_decode($regularAtt->contentBase64))->toBe('sample file content');

    $inlineAtt = $payload->attachments[1];
    expect($inlineAtt->filename)->toBe('logo.png')
        ->and($inlineAtt->mimeType)->toBe('image/png')
        ->and($inlineAtt->isInline)->toBeTrue()
        ->and($inlineAtt->contentId)->not->toBeNull()
        ->and(base64_decode($inlineAtt->contentBase64))->toBe('inline image content');
});

it('extracts tags and metadata from headers', function () {
    $email = new Email;
    $email->from('sender@example.com')
        ->to('recipient@example.com')
        ->subject('Tags & Metadata Test')
        ->text('Hello');

    $email->getHeaders()->addTextHeader('X-Tag', 'newsletter, promo');
    $email->getHeaders()->addTextHeader('X-Metadata-user_id', 'user_42');
    $email->getHeaders()->addTextHeader('X-Metadata', json_encode(['tier' => 'gold', 'region' => 'eu']));

    $normalizer = new PayloadNormalizer;
    $payload = $normalizer->normalize($email);

    expect($payload->tags)->toContain('newsletter', 'promo')
        ->and($payload->metadata)->toHaveKey('user_id', 'user_42')
        ->and($payload->metadata)->toHaveKey('tier', 'gold')
        ->and($payload->metadata)->toHaveKey('region', 'eu')
        ->and($payload->headers)->not->toHaveKey('X-Tag')
        ->and($payload->headers)->not->toHaveKey('X-Metadata-user_id');
});
