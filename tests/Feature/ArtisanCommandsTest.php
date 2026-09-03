<?php

declare(strict_types=1);

use Eudeka\LaravelMailer\Resilience\CircuitBreaker;
use Illuminate\Support\Facades\Http;

it('runs mailer:status and displays the providers table', function () {
    config()->set('mailer.priority', ['resend', 'brevo', 'smtp2go']);
    config()->set('mailer.providers.resend.api_key', 're_key');
    config()->set('mailer.providers.brevo.api_key', null);
    config()->set('mailer.providers.smtp2go.api_key', 'smtp_key');

    /** @var CircuitBreaker $breaker */
    $breaker = app(CircuitBreaker::class);
    $breaker->trip('smtp2go', 45);

    $this->artisan('mailer:status')
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

it('runs mailer:test successfully when provider delivers', function () {
    config()->set('mailer.priority', ['resend']);
    config()->set('mailer.providers.resend.api_key', 're_key');

    Http::fake([
        'https://api.resend.com/emails' => Http::response(['id' => 're_test_cmd_123'], 200),
    ]);

    $this->artisan('mailer:test test@example.com --subject="Custom Subject"')
        ->expectsOutputToContain('Dispatching test email to [test@example.com]')
        ->assertSuccessful();
});

it('handles failure in mailer:test when all providers fail', function () {
    config()->set('mailer.priority', ['resend']);
    config()->set('mailer.providers.resend.api_key', 're_key');

    Http::fake([
        'https://api.resend.com/emails' => Http::response(['message' => 'Invalid API key'], 401),
    ]);

    $this->artisan('mailer:test fail@example.com')
        ->expectsOutputToContain('All configured providers failed to deliver the email')
        ->assertFailed();
});
