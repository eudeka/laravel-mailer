<?php

declare(strict_types=1);

use Eudeka\LaravelMailer\Contracts\EmailProviderInterface;
use Eudeka\LaravelMailer\DTO\NormalizedEmailPayload;
use Eudeka\LaravelMailer\DTO\ProviderResponse;
use Eudeka\LaravelMailer\Pipeline\ProviderRegistry;

it('dynamically prunes providers with missing credentials', function () {
    $registry = new ProviderRegistry(
        priority: ['resend', 'brevo', 'smtp2go'],
        config: [
            'resend' => ['api_key' => 're_12345'],
            'brevo' => ['api_key' => null],
            'smtp2go' => ['api_key' => ''],
        ],
    );

    $all = $registry->all();
    expect($all)->toHaveKeys(['resend', 'brevo', 'smtp2go']);

    $active = $registry->active();
    expect($active)->toHaveKey('resend')
        ->and($active)->not->toHaveKey('brevo')
        ->and($active)->not->toHaveKey('smtp2go')
        ->and($active['resend']->hasCredentials())->toBeTrue();

    $pruned = $registry->pruned();
    expect($pruned)->toHaveKeys(['brevo', 'smtp2go']);
});

it('supports custom provider registration', function () {
    $registry = new ProviderRegistry(
        priority: ['custom_vendor', 'resend'],
        config: [
            'resend' => ['api_key' => 're_test'],
        ],
    );

    $customProvider = new class implements EmailProviderInterface
    {
        public function name(): string
        {
            return 'custom_vendor';
        }

        public function hasCredentials(): bool
        {
            return true;
        }

        public function send(NormalizedEmailPayload $payload): ProviderResponse
        {
            return ProviderResponse::success($this->name(), 200, 'custom-id');
        }
    };

    $registry->registerCustomProvider('custom_vendor', fn () => $customProvider);

    $active = $registry->active();
    expect($active)->toHaveKeys(['custom_vendor', 'resend'])
        ->and($active['custom_vendor']->name())->toBe('custom_vendor');
});
