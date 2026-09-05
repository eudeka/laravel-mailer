# Release Notes

## [Unreleased](https://github.com/eudeka/laravel-mailer/compare/v1.1.0...1.x)

## [v1.1.0] - 2026-09-05

Standardized transport invariants, enhanced deliverability and data preservation, interactive Workbench testing, and comprehensive test suite additions.

### Highlights & Features

- **Standardized Mail Transport Invariants**: Established strict transport invariants across all drivers (Zero-SDK REST, immediate failover without internal retries, deterministic idempotency keys, strict 2xx response validation, and enriched HTTP 429 rate limit errors).
- **Deliverability & Data Preservation**:
    - RFC-5322 quoted display names (`"Name" <email>`) across Brevo, Resend, and SMTP2GO transports.
    - Automatic plain-text body generation via HTML tag stripping fallback when plain-text body is omitted.
    - Idempotency key resolution from headers (`Idempotency-Key` / `X-Idempotency-Key`) or deterministic SHA-256 fingerprint (`from|to|subject|date`) with ASCII sanitization.
    - Enriched HTTP 429 rate limit exceptions with parsed `Retry-After` delay duration.
    - Consistent User-Agent identification (`eudeka-laravel-mailer/1.0`) across all HTTP calls to prevent Cloudflare/WAF blockades.
    - Safe recipient and sender name length truncation (70 chars for Brevo) to avoid provider rejections.
    - Preserved `Reply-To`, `In-Reply-To`, and `References` headers without stripping.
- **Workbench Dual-Mode Delivery Testing**: Interactive Workbench UI supporting dual-mode email delivery testing (Plain Text vs Rich HTML with inline embedded images) and per-transport selection.
- **Architectural Documentation**: Added comprehensive architectural guide (`docs/mail-transport-guide.md`) detailing invariants, driver specifications, and design decisions.
- **Comprehensive Test Suite & Quality**:
    - Dedicated unit test suite for `ExtractsEmailData` trait testing address formatting, plain-text fallback, idempotency resolution, metadata extraction, attachment separation, and error formatting in isolation.
    - Complete edge case test coverage (empty recipient validation, fallback sender handling, publish tag registration, installer injection patterns).
    - Maintained 100% type coverage, 0 PHPStan errors, and 100% Pest test pass rate across 102 tests.

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
