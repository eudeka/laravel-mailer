---
name: laravel-mailer-development
description: >
  Configure and apply the Laravel Mailer package in Laravel applications.
license: MIT
metadata:
  author: Eudeka
---

# Laravel Mailer

Use this skill when a Laravel application needs to integrate the `eudeka/laravel-mailer` package.

## Primary Goal

- Apply the `eudeka/laravel-mailer` package in the smallest correct way using standard Laravel Mail APIs and native failover.

## Workflow

### 1. Inspect the Laravel app context

- Confirm the app is a Laravel project.
- Inspect the target code paths where email dispatch is configured or used.

### 2. Apply the package

- Install from repository via Composer:
  ```bash
  composer require eudeka/laravel-mailer
  ```

- Run the automated installer to configure host `config/mail.php` and `.env` / `.env.example`:
  ```bash
  php artisan eudeka:mailer-install
  ```

- Configure environment variables in `.env`:
  ```env
  # Set default mailer to native failover or a specific provider
  MAIL_MAILER=failover

  # Set provider priority order
  MAIL_FAILOVER_MAILERS=brevo,resend,smtp2go

  # Set provider API credentials
  MAILER_BREVO_API_KEY=xkeysib-123456789abcdef
  MAILER_RESEND_API_KEY=re_123456789abcdef
  MAILER_SMTP2GO_API_KEY=api-123456789abcdef
  ```

- Send mail using standard Laravel `Mail` facade or notifications—no custom facades or code modifications needed.

## Rules, References, and Templates

Read before executing:

- `src/LaravelMailerServiceProvider.php`
- `src/Transport/BrevoApiTransport.php`
- `src/Transport/ResendApiTransport.php`
- `src/Transport/Smtp2GoApiTransport.php`
- `src/Commands/MailerInstallCommand.php`

## Examples

- Send mail using standard Laravel Mail facade:
  ```php
  use App\Mail\WelcomeMailable;
  use Illuminate\Support\Facades\Mail;

  Mail::to('user@example.com')->send(new WelcomeMailable);
  ```

- Send queued mail:
  ```php
  Mail::to('user@example.com')->queue(new OrderShippedMailable($order));
  ```

- Send via specific mailer driver:
  ```php
  Mail::mailer('brevo')->to('user@example.com')->send(new TransactionalMailable);
  ```

## Anti-patterns

- Do not call or expect custom facades like `LaravelMailer::send()`; standard Laravel `Mail` handles all dispatches.
- Do not publish configuration files unless custom overrides are strictly required; the package is zero-config.
