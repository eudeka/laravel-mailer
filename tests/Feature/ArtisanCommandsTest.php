<?php

declare(strict_types=1);

use EmailProvider\EmailProvider\Resilience\CircuitBreaker;
use Illuminate\Support\Facades\Http;

it('runs email-provider:status and displays the providers table', function () {
    config()->set('email-provider.priority', ['resend', 'brevo', 'smtp2go']);
    config()->set('email-provider.providers.resend.api_key', 're_key');
    config()->set('email-provider.providers.brevo.api_key', null);
    config()->set('email-provider.providers.smtp2go.api_key', 'smtp_key');

    /** @var CircuitBreaker $breaker */
    $breaker = app(CircuitBreaker::class);
    $breaker->trip('smtp2go', 45);

    $this->artisan('email-provider:status')
        ->expectsTable(
            ['Priority', 'Provider', 'Credentials', 'Circuit Breaker', 'Effective State'],
            [
                [1, 'resend', '<fg=green>Configured</>', '<fg=green>Healthy</>', '<fg=green;options=bold>Active</>'],
                [2, 'brevo', '<fg=red>Missing (Pruned)</>', '<fg=green>Healthy</>', '<fg=gray>Pruned</>'],
                [3, 'smtp2go', '<fg=green>Configured</>', '<fg=yellow>Cooldown (45s remaining)</>', '<fg=yellow>Degraded (In Cooldown)</>'],
            ],
        )
        ->assertSuccessful();
});

it('runs email-provider:test successfully when provider delivers', function () {
    config()->set('email-provider.priority', ['resend']);
    config()->set('email-provider.providers.resend.api_key', 're_key');

    Http::fake([
        'https://api.resend.com/emails' => Http::response(['id' => 're_test_cmd_123'], 200),
    ]);

    $this->artisan('email-provider:test test@example.com --subject="Custom Subject"')
        ->expectsOutputToContain('Dispatching test email to [test@example.com]')
        ->assertSuccessful();
});

it('handles failure in email-provider:test when all providers fail', function () {
    config()->set('email-provider.priority', ['resend']);
    config()->set('email-provider.providers.resend.api_key', 're_key');

    Http::fake([
        'https://api.resend.com/emails' => Http::response(['message' => 'Invalid API key'], 401),
    ]);

    $this->artisan('email-provider:test fail@example.com')
        ->expectsOutputToContain('All configured providers failed to deliver the email')
        ->assertFailed();
});
