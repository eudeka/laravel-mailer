<?php

declare(strict_types=1);

use EmailProvider\EmailProvider\Resilience\CircuitBreaker;
use Illuminate\Support\Facades\Cache;

it('identifies healthy providers as available by default', function () {
    $cache = Cache::store('array');
    $breaker = new CircuitBreaker(cache: $cache, enabled: true, defaultCooldownSeconds: 60);

    expect($breaker->isAvailable('resend'))->toBeTrue()
        ->and($breaker->getRemainingCooldown('resend'))->toBe(0);
});

it('trips the circuit breaker and calculates cooldown', function () {
    $cache = Cache::store('array');
    $breaker = new CircuitBreaker(cache: $cache, enabled: true, defaultCooldownSeconds: 30);

    $breaker->trip('resend', 30);

    expect($breaker->isAvailable('resend'))->toBeFalse()
        ->and($breaker->getRemainingCooldown('resend'))->toBeGreaterThan(0)
        ->and($breaker->getRemainingCooldown('resend'))->toBeLessThanOrEqual(30);
});

it('resets the circuit breaker upon success', function () {
    $cache = Cache::store('array');
    $breaker = new CircuitBreaker(cache: $cache, enabled: true, defaultCooldownSeconds: 60);

    $breaker->trip('brevo', 60);
    expect($breaker->isAvailable('brevo'))->toBeFalse();

    $breaker->reset('brevo');
    expect($breaker->isAvailable('brevo'))->toBeTrue()
        ->and($breaker->getRemainingCooldown('brevo'))->toBe(0);
});

it('always returns available when circuit breaker is disabled', function () {
    $cache = Cache::store('array');
    $breaker = new CircuitBreaker(cache: $cache, enabled: false, defaultCooldownSeconds: 60);

    $breaker->trip('smtp2go', 60);

    expect($breaker->isEnabled())->toBeFalse()
        ->and($breaker->isAvailable('smtp2go'))->toBeTrue()
        ->and($breaker->getRemainingCooldown('smtp2go'))->toBe(0);
});
