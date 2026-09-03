<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Priority Execution Order
    |--------------------------------------------------------------------------
    |
    | Defines the sequential fallback priority across supported email vendors.
    | Any provider missing valid API credentials will be automatically pruned
    | during initialization to prevent unnecessary network hops.
    |
    */
    'priority' => [
        'resend',
        'brevo',
        'smtp2go',
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Configuration
    |--------------------------------------------------------------------------
    |
    | When enabled, providers experiencing rate limits (HTTP 429) or remote
    | server errors (HTTP 5xx) enter a temporary cooldown state in cache,
    | allowing subsequent outbound emails to skip the failing provider.
    |
    */
    'circuit_breaker' => [
        'enabled' => (bool) env('EMAIL_PROVIDER_CIRCUIT_BREAKER_ENABLED', true),
        'cooldown_seconds' => (int) env('EMAIL_PROVIDER_COOLDOWN_SECONDS', 60),
        'cache_store' => env('EMAIL_PROVIDER_CACHE_STORE'),
        'cache_prefix' => (string) env('EMAIL_PROVIDER_CACHE_PREFIX', 'email_provider_breaker:'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vendor Providers Configuration
    |--------------------------------------------------------------------------
    |
    | Configure API credentials, REST endpoints, and request timeouts for each
    | supported email provider.
    |
    */
    'providers' => [
        'resend' => [
            'api_key' => env('RESEND_API_KEY'),
            'endpoint' => env('RESEND_ENDPOINT', 'https://api.resend.com/emails'),
            'timeout' => (int) env('RESEND_TIMEOUT', 10),
        ],

        'brevo' => [
            'api_key' => env('BREVO_API_KEY'),
            'endpoint' => env('BREVO_ENDPOINT', 'https://api.brevo.com/v3/smtp/email'),
            'timeout' => (int) env('BREVO_TIMEOUT', 10),
        ],

        'smtp2go' => [
            'api_key' => env('SMTP2GO_API_KEY'),
            'endpoint' => env('SMTP2GO_ENDPOINT', 'https://api.smtp2go.com/v3/email/send'),
            'timeout' => (int) env('SMTP2GO_TIMEOUT', 10),
        ],
    ],

];
