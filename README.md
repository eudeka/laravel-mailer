<div align="center">
    <h1>Laravel Multi-Vendor Email Provider</h1>
    <p>Zero-SDK REST-based email transport with dynamic runtime pruning, sequential failover, and cache-backed circuit breaker for Laravel 11 & 12+.</p>
</div>

<p align="center">
    <a href="https://packagist.org/packages/eudeka/email-provider"><img src="https://img.shields.io/packagist/v/eudeka/email-provider.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/eudeka/email-provider"><img src="https://img.shields.io/packagist/php-v/eudeka/email-provider.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://badge.laravel.cloud/badge/eudeka/email-provider?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/eudeka/email-provider/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/eudeka/email-provider/tests.yml?branch=main&label=Tests&style=flat-square"></a>
</p>

---

## Highlights

- **Zero-SDK REST Architecture**: Pure HTTP abstraction layer for **Resend**, **Brevo (Sendinblue)**, and **SMTP2GO** without third-party vendor SDK dependencies or bloat.
- **Dynamic Runtime Pruning**: Evaluates global priority during boot. Any provider lacking valid API credentials is automatically pruned from the execution sequence, preventing redundant HTTP requests.
- **Fault-Tolerant Sequential Failover**: Intercepts 4xx/5xx errors, rate limits, and connection timeouts, immediately routing the normalized payload to the next available provider.
- **Cache-Backed Circuit Breaker**: Providers hitting rate limits (429) or server errors (5xx) enter a configurable cooldown (default: 60s) so subsequent emails bypass degraded nodes instantly.
- **Full Laravel Integration**: Seamlessly integrates into `Mail::to()`, Mailables, Notifications, and queued jobs as a standard Laravel mail transport driver.
- **Rich Payload Normalization**: Fully normalizes recipients (To, CC, BCC, Reply-To), multipart HTML/text, standard file attachments, and inline CID embedded images (`$message->embed()`).
- **Observability & Events**: Dispatches native Laravel events (`EmailSentViaProvider`, `ProviderAttemptFailed`, `AllProvidersFailed`) and logs structured warnings.
- **CLI Management**: Includes `php artisan email-provider:status` and `php artisan email-provider:test {recipient}`.

---

## Installation

Install the package via Composer:

```bash
composer require eudeka/email-provider
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag="email-provider-config"
```

---

## Configuration

### 1. Configure Mailer Transport

Add the `multi-vendor` mailer to your `config/mail.php`:

```php
'mailers' => [
    // ...
    'multi-vendor' => [
        'transport' => 'multi-vendor',
    ],
],
```

Set it as your default mailer in `.env`:

```env
MAIL_MAILER=multi-vendor
```

### 2. Configure Credentials

Add the API credentials for your chosen email services to `.env`:

```env
# Priority sequence (comma-separated or configured in config/email-provider.php)
RESEND_API_KEY=re_123456789
BREVO_API_KEY=xkeysib-123456789
SMTP2GO_API_KEY=api-123456789
```

In `config/email-provider.php`, configure your desired priority sequence and circuit breaker options:

```php
return [
    /*
    |--------------------------------------------------------------------------
    | Provider Priority Sequence
    |--------------------------------------------------------------------------
    | Providers missing valid API keys are pruned from the sequence at runtime.
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
    */
    'circuit_breaker' => [
        'enabled' => true,
        'cooldown_seconds' => (int) env('EMAIL_CIRCUIT_BREAKER_COOLDOWN', 60),
        'cache_store' => null, // null uses default cache store
        'cache_prefix' => 'email_provider_breaker:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Credentials & Endpoints
    |--------------------------------------------------------------------------
    */
    'providers' => [
        'resend' => [
            'api_key' => env('RESEND_API_KEY'),
            'endpoint' => env('RESEND_API_ENDPOINT', 'https://api.resend.com/emails'),
            'timeout' => (int) env('RESEND_TIMEOUT', 15),
        ],
        'brevo' => [
            'api_key' => env('BREVO_API_KEY'),
            'endpoint' => env('BREVO_API_ENDPOINT', 'https://api.brevo.com/v3/smtp/email'),
            'timeout' => (int) env('BREVO_TIMEOUT', 15),
        ],
        'smtp2go' => [
            'api_key' => env('SMTP2GO_API_KEY'),
            'endpoint' => env('SMTP2GO_API_ENDPOINT', 'https://api.smtp2go.com/v3/email/send'),
            'timeout' => (int) env('SMTP2GO_TIMEOUT', 15),
        ],
    ],
];
```

---

## Usage

### Standard Laravel Mail

Because `email-provider` registers as a native Symfony Mailer transport, you write standard Laravel code:

```php
use Illuminate\Support\Facades\Mail;
use App\Mail\InvoiceMailable;

// Using standard Mail facade:
Mail::to('client@example.com')->send(new InvoiceMailable($invoice));

// Queued emails work out of the box:
Mail::to('client@example.com')->queue(new InvoiceMailable($invoice));
```

### Checking Provider Health & Status

Inspect which providers are active, pruned (missing credentials), or temporarily in circuit-breaker cooldown:

```bash
php artisan email-provider:status
```

Example output:
```text
+----------+----------+--------------------+------------------------+-----------------+
| Priority | Provider | Credentials        | Circuit Breaker        | Effective State |
+----------+----------+--------------------+------------------------+-----------------+
| 1        | resend   | Configured         | Healthy                | Active          |
| 2        | brevo    | Missing (Pruned)   | Healthy                | Pruned          |
| 3        | smtp2go  | Configured         | Cooldown (45s remain)  | Degraded        |
+----------+----------+--------------------+------------------------+-----------------+
```

### Testing Deliverability via CLI

Send a test email through the active failover pipeline:

```bash
php artisan email-provider:test recipient@example.com --subject="Test Email" --body="Testing multi-vendor mailer"
```

---

## Events & Observability

You can listen to lifecycle events in your `EventServiceProvider`:

| Event | Dispatched When |
|---|---|
| `EmailProvider\EmailProvider\Events\EmailSentViaProvider` | Email was successfully accepted by a provider. Contains `$providerName`, `$payload`, `$response`, and `$attempts`. |
| `EmailProvider\EmailProvider\Events\ProviderAttemptFailed` | A provider returned a non-2xx response or timed out. Contains `$providerName`, `$response`, `$exception`, and `$trippedCircuitBreaker`. |
| `EmailProvider\EmailProvider\Events\AllProvidersFailed` | All configured providers in the execution sequence failed. Contains `$failures` map. |

---

## Adding Custom Providers

You can extend the provider registry with your own custom provider implementation:

```php
use EmailProvider\EmailProvider\Facades\EmailProvider;
use EmailProvider\EmailProvider\Contracts\EmailProviderInterface;
use EmailProvider\EmailProvider\DTO\NormalizedEmailPayload;
use EmailProvider\EmailProvider\DTO\ProviderResponse;

class PostmarkCustomProvider implements EmailProviderInterface
{
    public function name(): string
    {
        return 'postmark';
    }

    public function hasCredentials(): bool
    {
        return ! empty(config('services.postmark.token'));
    }

    public function send(NormalizedEmailPayload $payload): ProviderResponse
    {
        // Your custom REST or HTTP client logic...
        return ProviderResponse::success($this->name(), 200, 'postmark_id');
    }
}

// In a service provider boot() method:
EmailProvider::extend('postmark', fn () => new PostmarkCustomProvider());
```

---

## Testing

Run the test suite:

```bash
composer test
```

Or individual checks:

```bash
composer lint:check   # Pint code style check
composer analyse      # PHPStan static analysis
composer test:types   # 100% type coverage check
composer test:unit    # Pest test suite
```

---

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
