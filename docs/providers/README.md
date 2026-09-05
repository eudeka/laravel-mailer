# Provider Documentation Directory & Blueprint Standard

This directory houses the technical reference documentation for each email delivery provider supported by `eudeka/laravel-mailer`.

Each document serves as a single source of truth for human engineers and AI coding agents, outlining the REST API integration, request/response JSON schemas, silent error behaviors, rate limits, and official documentation links.

---

## 1. Supported Providers Index

| Provider    | Transport Class                                                      | Primary API Endpoint                    | Auth Header                   | Failover Priority | Documentation              |
| :---------- | :------------------------------------------------------------------- | :-------------------------------------- | :---------------------------- | :---------------- | :------------------------- |
| **Brevo**   | [`BrevoApiTransport`](../../src/Transport/BrevoApiTransport.php)     | `https://api.brevo.com/v3/smtp/email`   | `api-key: {key}`              | Primary (#1)      | [`brevo.md`](brevo.md)     |
| **Resend**  | [`ResendApiTransport`](../../src/Transport/ResendApiTransport.php)   | `https://api.resend.com/emails`         | `Authorization: Bearer {key}` | Secondary (#2)    | [`resend.md`](resend.md)   |
| **SMTP2GO** | [`Smtp2GoApiTransport`](../../src/Transport/Smtp2GoApiTransport.php) | `https://api.smtp2go.com/v3/email/send` | `X-Smtp2go-Api-Key: {key}`    | Tertiary (#3)     | [`smtp2go.md`](smtp2go.md) |

---

## 2. Standard Blueprint for New Provider Documentation

Whenever a new email delivery provider is added to `eudeka/laravel-mailer`, a corresponding documentation file **MUST** be created at `docs/providers/[provider-name].md`.

To maintain consistency for developers and AI agents, every provider document must adhere strictly to the 6-section blueprint below.

````markdown
# [Provider Name] Transport Specification

Technical specification and integration details for [Provider Name] in `eudeka/laravel-mailer`.

---

## 1. Overview & Authentication

- **Transport Class**: `Eudeka\LaravelMailer\Transport\[Provider]ApiTransport`
- **Official Website**: https://provider.example.com
- **API Base URL**: `https://api.provider.example.com/v1/...`
- **HTTP Method**: `POST`
- **Authentication Scheme**: Header name and format (e.g., `Authorization: Bearer {key}` or `api-key: {key}`)
- **Required Headers**:
    - `Content-Type: application/json`
    - `Accept: application/json`
    - `User-Agent: eudeka-laravel-mailer/1.0`

---

## 2. Configuration & Environment Variables

| Environment Variable         | Internal Config Key (`config/mailers.php`) | Default Value | Description                                                   |
| :--------------------------- | :----------------------------------------- | :------------ | :------------------------------------------------------------ |
| `MAILER_[PROVIDER]_API_KEY`  | `mail.mailers.[provider].key`              | `null`        | API key for authentication (fallback to `[PROVIDER]_API_KEY`) |
| `MAILER_[PROVIDER]_ENDPOINT` | `mail.mailers.[provider].endpoint`         | Official URL  | Custom API endpoint for proxies or local mocks                |
| `MAILER_[PROVIDER]_TIMEOUT`  | `mail.mailers.[provider].timeout`          | `10`          | Request timeout in seconds                                    |

---

## 3. Request Payload Schema & Mapping

Detail the exact JSON payload format accepted by the provider's REST API and how Laravel's `Symfony\Component\Mime\Email` attributes map into it.

### Example Request JSON Payload

```json
{
  ...
}
```
````

### Field Mapping Rules

- **Sender (`from`)**: String format vs object `{email, name}`.
- **Recipients (`to`, `cc`, `bcc`)**: Array of strings vs array of objects.
- **Body (`html`, `text`)**: Key names for HTML and plain-text fallback.
- **Attachments & Inlines**: How physical attachments and inline CID embedded images are structured.
- **Tags & Metadata**: How tracking tags and custom attributes are mapped.
- **Idempotency Key**: Whether passed as an HTTP header (`Idempotency-Key`) or within the JSON body.

---

## 4. Response Schema & Silent Failure Detection

### Successful Response (HTTP 2xx)

```json
{
  ...
}
```

- **Message ID Location**: JSON path to the accepted message ID (e.g., `id` or `messageId`).

### Error Response & Silent Failure Detection

- Detail any provider anomalies where the API returns HTTP 200/202 but actually indicates delivery failure (e.g. `data.failed > 0` or empty IDs).
- Detail error status codes (400, 401, 402, 403, 429, 500) and how error messages are parsed.

---

## 5. Quirks, Edge Cases & Account Constraints

| Edge Case / Condition      | Provider Behavior     | Transport Mitigation                            |
| :------------------------- | :-------------------- | :---------------------------------------------- |
| **Display Name Length**    | Limit in characters   | String truncation (`mb_substr`)                 |
| **Max Attachment Size**    | Max MB total          | Preflight size check or fail-fast               |
| **Rate Limiting (429)**    | RPS / RPH limit       | Parse `Retry-After` header into exception       |
| **Account Credits (402)**  | Out of funds          | Instant failover via `TransportException`       |
| **WAF / Cloudflare Block** | HTTP 403 (Error 1010) | Enforce `User-Agent: eudeka-laravel-mailer/1.0` |

---

## 6. Official Documentation & AI/LLM Resources

Curated links to official provider documentation formatted for both human reading and AI agent retrieval:

| Resource                     | URL                                     | Description                                                  |
| :--------------------------- | :-------------------------------------- | :----------------------------------------------------------- |
| **LLMs Index**               | `https://provider.example.com/llms.txt` | Complete documentation sitemap formatted for LLM consumption |
| **Send Email API Reference** | `https://provider.example.com/docs/...` | Interactive endpoint explorer and parameter specifications   |
| **Error Codes & Handling**   | `https://provider.example.com/docs/...` | Status code reference and error envelope definitions         |
| **Rate Limits & Quotas**     | `https://provider.example.com/docs/...` | Rate limit headers and concurrency caps                      |

```

```
