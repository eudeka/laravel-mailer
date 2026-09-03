---
name: laravel-mailer-development
description: >
  Configure and apply the Laravel Mailer package in Laravel applications.
license: MIT
metadata:
  author: Eudeka
---

# Laravel Mailer

Use this skill when a Laravel application needs to integrate the Laravel Mailer package.

## Primary Goal

- apply the `eudeka/laravel-mailer` package's public API in the smallest correct way

## Workflow

### 1. Inspect the Laravel app context

- confirm the app is a Laravel project
- inspect the target code paths where the package should be applied

### 2. Apply the package's public API

- Install from private repository via Composer:
  ```json
  "repositories": [
      {
          "type": "vcs",
          "url": "git@github.com:eudeka/laravel-mailer.git"
      }
  ]
  ```
  Run `composer require eudeka/laravel-mailer`.
- Publish the configuration:
  `php artisan vendor:publish --tag="mailer-config"`
- Register the mailer transport driver in `config/mail.php`:
  ```php
  'mailers' => [
      'multi-vendor' => [
          'transport' => 'multi-vendor',
      ],
  ],
  ```
- Configure environment variables in `.env` (`MAIL_MAILER=multi-vendor`, `RESEND_API_KEY`, `BREVO_API_KEY`, etc.).
- Inspect status via `php artisan mailer:status`.

## Rules, References, and Templates

Read before executing:

- `config/mailer.php`
- `src/LaravelMailer.php`
- `src/Facades/LaravelMailer.php`
- `src/Transport/MultiVendorTransport.php`

## Examples

- Send mail using standard Laravel Mail facade:
  ```php
  Mail::to('user@example.com')->send(new WelcomeMailable);
  ```
- Extend custom email providers via `LaravelMailer::extend()`:
  ```php
  LaravelMailer::extend('my-gateway', fn () => new MyGatewayProvider);
  ```

## Anti-patterns

- do not document package internals here; keep the skill focused on adoption in Laravel apps
