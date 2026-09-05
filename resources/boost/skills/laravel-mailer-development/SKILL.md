---
name: laravel-mailer-development
description: "Use this skill when installing, configuring, or using eudeka/laravel-mailer in a Laravel 13+ application. Trigger when setting up email transports (Brevo, Resend, SMTP2GO), configuring multi-provider failover, sending transactional emails via Laravel's native Mail facade, writing Mailables, configuring environment variables, or testing email dispatches with Mail::fake() or Http::fake(). Skip when configuring non-email notifications or when maintaining internal package source code."
license: MIT
metadata:
    author: Eudeka
---

# Laravel Mailer

Use this skill when a Laravel application integrates `eudeka/laravel-mailer` for zero-SDK REST-based email delivery with automatic failover.

## Primary Goal

Apply the `eudeka/laravel-mailer` package in the smallest correct way using standard Laravel 13 Mail APIs, zero external SDKs, and native Symfony failover.

## Workflow

### 1. Inspect the Laravel app context

- Confirm the app is a Laravel 13+ project (`php ^8.4`, `illuminate/support ^13.0`).
- Inspect target code paths where email dispatch, notification, or mail configuration is used.

### 2. Register private VCS repository & install package

- Add the private VCS repository via Composer CLI:
    ```bash
    # Option A: SSH (Default & Recommended)
    composer config repositories.laravel-mailer vcs git@github.com:eudeka/laravel-mailer.git

    # Option B: Fallback via HTTPS + GitHub Personal Access Token (PAT)
    composer config repositories.laravel-mailer vcs https://github.com/eudeka/laravel-mailer.git
    composer config --global github-oauth.github.com <YOUR_GITHUB_TOKEN>
    ```
- Require the package using a strict SemVer constraint (`^1.0`). **Never use `dev-main`**:
    ```bash
    composer require "eudeka/laravel-mailer:^1.0"
    ```
- Run the setup helper to configure host `config/mail.php` failover array and populate `.env` / `.env.example`:
    ```bash
    php artisan eudeka:mailer-install
    ```

### 3. Configure environment variables

- Configure `.env`:
    ```env
    MAIL_MAILER=failover
    MAIL_FROM_ADDRESS="noreply@yourdomain.com"
    MAIL_FROM_NAME="${APP_NAME}"
    MAIL_FAILOVER_MAILERS=brevo,resend,smtp2go
    MAILER_BREVO_API_KEY=xkeysib-123456789abcdef
    MAILER_RESEND_API_KEY=re_123456789abcdef
    MAILER_SMTP2GO_API_KEY=api-123456789abcdef
    ```
- Zero-config rule: Configuration files do not need to be published unless custom overrides are strictly required.

### 4. Dispatch emails using standard Laravel 13 APIs

- Generate Mailables with `php artisan make:mail`.
- Use modern `Envelope`, `Content`, `Attachments`, and `Headers` methods.
- Dispatch via default failover (`Mail::to()->send()` or `Mail::to()->queue()`), or specify a specific driver via `Mail::mailer('brevo')->to()->send()`.

### 5. Test and verify in the application

- Local Development: Set `MAIL_MAILER=log` to avoid consuming third-party API quotas.
- Application Logic Testing: Test with `Mail::fake()`, asserting `Mail::assertSent()`.
- Failover Verification: Test provider transitions using `Http::fake()`.

## Rules, References, and Templates

Read before executing:

- Official Laravel 13 Mail Documentation: https://laravel.com/docs/13.x/mail
- Mail Transport Architecture & Extension Guide: `docs/mail-transport-guide.md` (Standard for adding new email transports, instant failover, idempotency, and test recipes)
- Provider Specifications & Directory: `docs/providers/README.md`
- Transports provided:
    - `failover`: Symfony `FailoverTransport` cycling through `MAIL_FAILOVER_MAILERS`.
    - `brevo`: Brevo v3 transactional email REST API (`https://api.brevo.com/v3/smtp/email`).
    - `resend`: Resend v1 emails REST API (`https://api.resend.com/emails`).
    - `smtp2go`: SMTP2GO `/email/send` REST API (`https://api.smtp2go.com/v3/email/send`).
- Setup Command: `php artisan eudeka:mailer-install`
- Safe Config Caching: Environment variables are internally resolved into `config/mailers.php`, safe for `php artisan config:cache`.

## Examples

### Example 1: Standard Mailable (Envelope, Content, Attachments)

```php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class OrderShippedMailable extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly object $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('orders@yourdomain.com', 'Store Orders'),
            subject: 'Order Shipped #'.$this->order->id,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.orders.shipped',
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromPath(storage_path("invoices/{$this->order->id}.pdf"))
                ->as('invoice.pdf')
                ->withMime('application/pdf'),
        ];
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Entity-ID' => (string) $this->order->id,
            ],
        );
    }
}
```

### Example 2: Sending Mail (Default Failover vs Specific Mailer)

```php
use App\Mail\OrderShippedMailable;
use Illuminate\Support\Facades\Mail;

// Default failover delivery (synchronous)
Mail::to($user->email)->send(new OrderShippedMailable($order));

// Queued delivery (recommended for production)
Mail::to($user->email)->queue(new OrderShippedMailable($order));

// Direct dispatch via a specific driver (bypassing failover)
Mail::mailer('brevo')->to($user->email)->send(new OrderShippedMailable($order));
Mail::mailer('resend')->to($user->email)->send(new OrderShippedMailable($order));
Mail::mailer('smtp2go')->to($user->email)->send(new OrderShippedMailable($order));
```

### Example 3: Tags, Custom Headers, and Inline Images

```php
// In Mailable class
public function build(): self
{
    return $this->withSymfonyMessage(function ($email) {
        $email->getHeaders()->addTextHeader('X-Tag', 'orders, transactional');
    });
}
```

Embedded images in Blade templates:

```html
<img src="{{ $message->embed(public_path('images/logo.png')) }}" alt="Logo" />
```

### Example 4: Testing Application Logic with Mail::fake()

```php
use App\Mail\OrderShippedMailable;
use Illuminate\Support\Facades\Mail;

it('dispatches an order shipped notification', function () {
    Mail::fake();

    // Trigger action
    $order = createOrder();

    // Assert email was sent
    Mail::assertSent(OrderShippedMailable::class, function ($mail) use ($order) {
        return $mail->hasTo($order->customer_email) &&
               $mail->order->id === $order->id;
    });
});
```

### Example 5: Testing Failover Sequence with Http::fake()

```php
use App\Mail\OrderShippedMailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

it('fails over to resend when brevo API is unavailable', function () {
    Http::fake([
        'api.brevo.com/*' => Http::response(['message' => 'Service Unavailable'], 503),
        'api.resend.com/*' => Http::response(['id' => 're_test_success'], 200),
    ]);

    Mail::to('user@example.com')->send(new OrderShippedMailable($order));

    // Brevo was attempted and failed
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.brevo.com'));
    // Resend was called successfully
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.resend.com'));
});
```

## Anti-patterns

- ❌ **DO NOT install external vendor SDKs**: Never run `composer require resend/resend-php` or `getbrevo/brevo-php`. The package provides its own zero-SDK REST transports.
- ❌ **DO NOT invent or call custom facades**: Never use `LaravelMailer::send()`. Always use Laravel's native `Illuminate\Support\Facades\Mail`.
- ❌ **DO NOT use `dev-main` in `composer.json`**: Always specify SemVer constraints (`^1.0` or exact release tags) to ensure reproducible builds in CI and team workspaces.
- ❌ **DO NOT publish configuration files unless strictly necessary**: The package is zero-config. Configuration files do not need to be published.
- ❌ **DO NOT call `env()` directly in Mailables or business logic**: Always let the framework and package resolve credentials via configuration to preserve `php artisan config:cache` compatibility.
- ❌ **DO NOT send real emails in local development or test suites**: Use `MAIL_MAILER=log` or `Mail::fake()`.
