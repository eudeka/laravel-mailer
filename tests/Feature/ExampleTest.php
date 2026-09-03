<?php

declare(strict_types=1);

use EmailProvider\EmailProvider\EmailProvider;

it('resolves the singleton', function () {
    expect(app(EmailProvider::class))->toBeInstanceOf(EmailProvider::class);
});

it('returns the same instance from the container', function () {
    expect(app(EmailProvider::class))->toBe(app(EmailProvider::class));
});

it('merges the package config', function () {
    expect(config('email-provider.placeholder'))->toBe('default');
});

it('loads the package translations', function () {
    expect(trans('email-provider::messages.placeholder'))->toBe('EmailProvider placeholder translation.');
});

it('loads the package views', function () {
    expect(view()->exists('email-provider::placeholder'))->toBeTrue();
});

it('registers the artisan command', function () {
    $this->artisan('email-provider:placeholder')
        ->expectsOutputToContain('EmailProvider placeholder command executed.')
        ->assertSuccessful();
});
