# Laravel Mailer

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `eudeka/laravel-mailer`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.

## Mail Transport Architectural Invariants

When adding or modifying email transports in this repository, always adhere to the standards documented in [`docs/mail-transport-guide.md`](docs/mail-transport-guide.md):

- **Zero-SDK REST**: Do NOT install external vendor SDKs. Use `Illuminate\Support\Facades\Http` directly.
- **Strict Instant Failover**: NEVER call `Http::retry()` inside the transport. Immediately throw `Symfony\Component\Mailer\Exception\TransportException` on any HTTP 4xx/5xx or cURL timeout so `FailoverTransport` switches cleanly.
- **Trait Sharing**: Always use `Eudeka\LaravelMailer\Transport\Concerns\ExtractsEmailData` for email normalization, plain-text fallback, and RFC address handling.
- **Deterministic Idempotency**: Always resolve and pass an idempotency key (header/payload) to protect queue retries against duplicate sends.
- **Strict 2xx Validation**: Do not blindly trust HTTP 200/201/202 responses. Inspect payload for silent errors and verify that a valid non-empty `messageId` was returned before calling `$message->setMessageId()`.
- **Enrich HTTP 429**: Parse the `Retry-After` header on 429 rate limit responses and include delay duration in the `TransportException` message.
- **User-Agent**: Always pass `'User-Agent' => 'eudeka-laravel-mailer/1.0'` to prevent Cloudflare / WAF blockades (e.g. Resend 1010).

## Quick Commands

- Full validation: `composer test`
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest tests: `composer test:unit`
- Workbench build: `composer build`
- Workbench server: `composer serve`
- Prettier docs files: `npx prettier . --write`

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4/5 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
- `package-generate-skill`: use when updating the bundled Boost skill from the package implementation, README, and examples.

## Release Conventions

- Trigger format: commit title MUST match `release: vX.Y.Z` or `chore(release): vX.Y.Z`.
- Version bump: the release commit must explicitly modify `"version"` in `composer.json` to match `X.Y.Z`.
- Changelog: `CHANGELOG.md` must contain section `## [vX.Y.Z] - YYYY-MM-DD` detailing highlights, changes, and fixes.
- Commit message body: mirror the release summary in the commit body beneath the commit title.
- Validation: always run `composer test` and `composer run build` locally before pushing a release commit.
- Automation: pushing to `main` automatically triggers `.github/workflows/release.yml` to validate, tag `vX.Y.Z`, and create GitHub Release with notes from `CHANGELOG.md`.
