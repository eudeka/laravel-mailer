# Release Notes

## [Unreleased](https://github.com/eudeka/laravel-mailer/compare/v1.0.0...1.x)

## [v1.0.0] - 2026-09-04

First official stable production release of `eudeka/laravel-mailer`.

### Highlights & Features
- **Zero-SDK REST Transports**: Native lightweight HTTP mail transports for **Brevo**, **Resend**, and **SMTP2GO** without third-party vendor SDKs.
- **Dynamic Failover Integration**: Direct integration with Laravel and Symfony `FailoverTransport` via `Mail::extend()` and automatic fallback recovery.
- **Safe Configuration Caching**: Full compatibility with `php artisan config:cache` via encapsulated config mapping (`config/mailers.php`).
- **Setup Command**: Automated setup helper `php artisan eudeka:mailer-install` to configure host application mail settings and `.env`.
- **Rich Message Support**: Standard mailables, attachments, inline Base64 Blade images, custom headers, and tracking tags.
- **Strict Quality Standards**: 100% type coverage, PHPStan max level 10, Pint PSR-12 styling, and comprehensive Pest test suite.
- **Framework & Runtime**: Exclusively targets **Laravel 13+** and **PHP 8.4+** / **PHP 8.5**.
- **Automated CI/CD**: Optimized GitHub Actions testing matrix on Ubuntu with Composer v2 caching, Workbench build, and tag-driven automated GitHub Releases.

## [v0.1.0](https://github.com/eudeka/laravel-mailer/compare/...v0.1.0) - 2026-09-01

Initial pre-release.
