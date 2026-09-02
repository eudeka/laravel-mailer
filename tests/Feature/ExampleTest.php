<?php

declare(strict_types=1);

use EmailProviderX\EmailProviderX\EmailProviderX;

it('resolves the singleton', function () {
    expect(app(EmailProviderX::class))->toBeInstanceOf(EmailProviderX::class);
});

it('returns the same instance from the container', function () {
    expect(app(EmailProviderX::class))->toBe(app(EmailProviderX::class));
});
