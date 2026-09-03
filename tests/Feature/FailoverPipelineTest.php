<?php

declare(strict_types=1);

use EmailProvider\EmailProvider\DTO\Address;
use EmailProvider\EmailProvider\DTO\NormalizedEmailPayload;
use EmailProvider\EmailProvider\Events\AllProvidersFailed;
use EmailProvider\EmailProvider\Events\EmailSentViaProvider;
use EmailProvider\EmailProvider\Events\ProviderAttemptFailed;
use EmailProvider\EmailProvider\Exceptions\AllProvidersFailedException;
use EmailProvider\EmailProvider\Exceptions\NoActiveProvidersException;
use EmailProvider\EmailProvider\Pipeline\FailoverPipeline;
use EmailProvider\EmailProvider\Pipeline\ProviderRegistry;
use EmailProvider\EmailProvider\Resilience\CircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

function testEmailPayload(): NormalizedEmailPayload
{
    return new NormalizedEmailPayload(
        from: new Address('sender@example.com', 'Acme'),
        to: [new Address('recipient@example.com', 'Recipient')],
        subject: 'Failover Test',
        html: '<p>Testing failover</p>',
    );
}

it('delivers successfully on the first provider without failover', function () {
    Event::fake();
    Http::fake([
        'https://api.resend.com/emails' => Http::response(['id' => 'resend_ok'], 200),
    ]);

    $registry = new ProviderRegistry(
        priority: ['resend', 'brevo'],
        config: [
            'resend' => ['api_key' => 're_key'],
            'brevo' => ['api_key' => 'br_key'],
        ],
    );

    $breaker = new CircuitBreaker(Cache::store('array'), true, 60);
    $pipeline = new FailoverPipeline($registry, $breaker);

    $response = $pipeline->send(testEmailPayload());

    expect($response->isSuccessful)->toBeTrue()
        ->and($response->providerName)->toBe('resend')
        ->and($response->messageId)->toBe('resend_ok');

    Event::assertDispatched(EmailSentViaProvider::class, function (EmailSentViaProvider $e) {
        return $e->providerName === 'resend' && $e->attempts === 1;
    });

    Event::assertNotDispatched(ProviderAttemptFailed::class);
    Event::assertNotDispatched(AllProvidersFailed::class);
});

it('fails over to second provider when first provider encounters 500 server error', function () {
    Event::fake();
    Http::fake([
        'https://api.resend.com/emails' => Http::response(['message' => 'Internal Server Error'], 500),
        'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<brevo_ok>'], 201),
    ]);

    $registry = new ProviderRegistry(
        priority: ['resend', 'brevo'],
        config: [
            'resend' => ['api_key' => 're_key'],
            'brevo' => ['api_key' => 'br_key'],
        ],
    );

    $breaker = new CircuitBreaker(Cache::store('array'), true, 60);
    $pipeline = new FailoverPipeline($registry, $breaker);

    $response = $pipeline->send(testEmailPayload());

    expect($response->isSuccessful)->toBeTrue()
        ->and($response->providerName)->toBe('brevo')
        ->and($response->messageId)->toBe('<brevo_ok>');

    // Breaker should have tripped for Resend
    expect($breaker->isAvailable('resend'))->toBeFalse()
        ->and($breaker->isAvailable('brevo'))->toBeTrue();

    Event::assertDispatched(ProviderAttemptFailed::class, function (ProviderAttemptFailed $e) {
        return $e->providerName === 'resend' && $e->response->statusCode === 500 && $e->trippedCircuitBreaker === true;
    });

    Event::assertDispatched(EmailSentViaProvider::class, function (EmailSentViaProvider $e) {
        return $e->providerName === 'brevo' && $e->attempts === 2;
    });
});

it('throws AllProvidersFailedException when all configured providers fail', function () {
    Event::fake();
    Http::fake([
        'https://api.resend.com/emails' => Http::response(['message' => 'Service Unavailable'], 503),
        'https://api.brevo.com/v3/smtp/email' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    $registry = new ProviderRegistry(
        priority: ['resend', 'brevo'],
        config: [
            'resend' => ['api_key' => 're_key'],
            'brevo' => ['api_key' => 'br_key'],
        ],
    );

    $breaker = new CircuitBreaker(Cache::store('array'), true, 60);
    $pipeline = new FailoverPipeline($registry, $breaker);

    expect(fn () => $pipeline->send(testEmailPayload()))
        ->toThrow(AllProvidersFailedException::class);

    Event::assertDispatched(AllProvidersFailed::class, function (AllProvidersFailed $e) {
        return count($e->failures) === 2;
    });
});

it('throws NoActiveProvidersException when no providers have valid credentials', function () {
    $registry = new ProviderRegistry(
        priority: ['resend', 'brevo'],
        config: [
            'resend' => ['api_key' => null],
            'brevo' => ['api_key' => ''],
        ],
    );

    $breaker = new CircuitBreaker(Cache::store('array'), true, 60);
    $pipeline = new FailoverPipeline($registry, $breaker);

    expect(fn () => $pipeline->send(testEmailPayload()))
        ->toThrow(NoActiveProvidersException::class);
});

it('skips providers currently in circuit breaker cooldown', function () {
    Event::fake();
    Http::fake([
        'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<brevo_ok>'], 201),
    ]);

    $registry = new ProviderRegistry(
        priority: ['resend', 'brevo'],
        config: [
            'resend' => ['api_key' => 're_key'],
            'brevo' => ['api_key' => 'br_key'],
        ],
    );

    $breaker = new CircuitBreaker(Cache::store('array'), true, 60);
    $breaker->trip('resend', 60); // Manually trip Resend

    $pipeline = new FailoverPipeline($registry, $breaker);

    $response = $pipeline->send(testEmailPayload());

    expect($response->isSuccessful)->toBeTrue()
        ->and($response->providerName)->toBe('brevo');

    // Resend was skipped, so no attempt was made to api.resend.com
    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'resend.com'));
});
