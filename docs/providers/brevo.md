# Brevo Transport Specification

Technical specification, REST API payload mapping, and integration details for Brevo (formerly Sendinblue) in `eudeka/laravel-mailer`.

---

## 1. Overview & Authentication

- **Transport Class**: [`Eudeka\LaravelMailer\Transport\BrevoApiTransport`](../../src/Transport/BrevoApiTransport.php)
- **Official Website**: https://www.brevo.com
- **API Base URL**: `https://api.brevo.com/v3/smtp/email`
- **HTTP Method**: `POST`
- **Authentication Scheme**: Custom header `api-key: {key}`
- **Required Headers**:
    - `api-key: {key}`
    - `Content-Type: application/json`
    - `Accept: application/json`
    - `User-Agent: eudeka-laravel-mailer/1.0`

---

## 2. Configuration & Environment Variables

| Environment Variable    | Internal Config Key (`config/mailers.php`) | Default Value                         | Description                                                    |
| :---------------------- | :----------------------------------------- | :------------------------------------ | :------------------------------------------------------------- |
| `MAILER_BREVO_API_KEY`  | `mail.mailers.brevo.key`                   | `null`                                | Primary Brevo API key (falls back to `BREVO_API_KEY`)          |
| `MAILER_BREVO_ENDPOINT` | `mail.mailers.brevo.endpoint`              | `https://api.brevo.com/v3/smtp/email` | Endpoint URL (falls back to `BREVO_ENDPOINT`)                  |
| `MAILER_BREVO_TIMEOUT`  | `mail.mailers.brevo.timeout`               | `10`                                  | HTTP client timeout in seconds (falls back to `BREVO_TIMEOUT`) |

---

## 3. Request Payload Schema & Mapping

Brevo expects a structured JSON object with typed recipient arrays and key-value parameter dictionaries.

### Example Request JSON Payload

```json
{
    "sender": {
        "name": "Acme Notifications",
        "email": "noreply@example.com"
    },
    "to": [
        {
            "name": "Jane Doe",
            "email": "jane@example.com"
        }
    ],
    "cc": [
        {
            "name": "Auditor",
            "email": "audit@example.com"
        }
    ],
    "replyTo": {
        "name": "Support Team",
        "email": "support@example.com"
    },
    "subject": "Your Order Confirmation",
    "htmlContent": "<html><body><h1>Order Confirmed</h1><img src=\"data:image/png;base64,iVBORw0KGgo...\" /></body></html>",
    "textContent": "Order Confirmed\nThank you for your order.",
    "attachment": [
        {
            "name": "invoice.pdf",
            "content": "JVBERi0xLjQKJ..."
        }
    ],
    "headers": {
        "Idempotency-Key": "4a7d65b7b62a67e5...",
        "X-Metadata-Order-Id": "10045"
    },
    "params": {
        "order_id": "10045"
    },
    "tags": ["orders", "transactional"]
}
```

### Field Mapping Rules

- **Sender (`sender`)**: Formatted as an object `{"email": "...", "name": "..."}`. Display name is truncated to 70 characters via `addressToArray($addr, 70)` to adhere to Brevo's strict validation.
- **Recipients (`to`, `cc`, `bcc`)**: Formatted as arrays of objects `[{"email": "...", "name": "..."}]` with 70-character name truncation.
- **Reply-To (`replyTo`)**: Formatted as a single object `{"email": "...", "name": "..."}`.
- **Content (`htmlContent`, `textContent`)**: HTML is placed in `htmlContent`. If no explicit plain-text body is provided, `resolvePlainTextBody()` strips tags from the HTML to populate `textContent`.
- **Inline CID Images**: Embedded CID images (`$message->embed()`) are converted directly into Base64 Data URIs (`data:{mime};base64,...`) and substituted into `htmlContent`, preventing broken inline images without polluting Brevo's attachment quota.
- **Attachments (`attachment`)**: Standard file attachments are Base64 encoded and placed in the singular `attachment` array with `name` and `content`.
- **Idempotency Key**: Attached inside the `headers` dictionary as `headers['Idempotency-Key']`.
- **Metadata (`params`)**: Mapped to Brevo's `params` dictionary and mirrored as `X-Metadata-{Key}` in `headers`.
- **Tags (`tags`)**: Array of plain-text tag strings.

---

## 4. Response Schema & Silent Failure Detection

### Successful Response (HTTP 201 Created)

```json
{
    "messageId": "<202609051030.123456789@smtp-relay.brevo.com>"
}
```

_Note: In some batch variations, Brevo may return `messageIds: ["<...>"]`._

### Silent Failure Detection & Error Parsing

`BrevoApiTransport` validates the following conditions:

1. **HTTP Status**: Any response with status `< 200` or `>= 300` immediately throws a `TransportException`.
2. **Missing `messageId`**: If the HTTP status is 2xx but neither `messageId` nor `messageIds.0` is present, a `TransportException` is thrown (email was rejected silently).
3. **Internal Error Flags**: If the JSON payload contains `code`, `error`, or numeric `failed > 0`, a `TransportException` is thrown.
4. **Error Schema**:
    ```json
    {
        "code": "invalid_parameter",
        "message": "Invalid email address in recipient list"
    }
    ```
    Formatted into: `Failed sending email via Brevo (HTTP 400): code: invalid_parameter, message: Invalid email address in recipient list`.

---

## 5. Quirks, Edge Cases & Account Constraints

| Edge Case / Condition               | Brevo Behavior                                                                | Transport Mitigation                                                                                   |
| :---------------------------------- | :---------------------------------------------------------------------------- | :----------------------------------------------------------------------------------------------------- |
| **70-Character Display Name Limit** | Returns HTTP 400 (`invalid_parameter`) if `name` exceeds 70 characters.       | Automatically truncated using `mb_substr($name, 0, 70)` in `ExtractsEmailData::addressToArray()`.      |
| **10MB Total Attachment Cap**       | Strictly rejects emails where cumulative Base64 attachment size exceeds 10MB. | Fail-fast early before transmission; failover routes to providers with higher limits (Resend/SMTP2GO). |
| **Inline CID Images**               | Native CID handling can cause missing images in webmail clients.              | Converted into Base64 Data URIs directly in `htmlContent`.                                             |
| **Blank Body**                      | Requires at least one of `htmlContent`, `textContent`, or `templateId`.       | Plain text auto-extracted from HTML, or defaults to fallback space.                                    |
| **Blank Subject**                   | Returns HTTP 400 (`missing_parameter`).                                       | Transport ensures subject is cast to non-empty string.                                                 |
| **Credits Exhausted (HTTP 402)**    | Returns HTTP 402 `Payment Required` when transactional quota is exhausted.    | Throws `TransportException` immediately, triggering instant failover to secondary provider.            |
| **Rate Limiting (HTTP 429)**        | Throttles if exceeding 1,000 RPS or 3.6M RPH.                                 | Reads `Retry-After` header and appends `(retry after Xs)` to exception.                                |

---

## 6. Official Documentation & AI/LLM Resources

| Resource                           | URL                                                                                                                        | Description                                                              |
| :--------------------------------- | :------------------------------------------------------------------------------------------------------------------------- | :----------------------------------------------------------------------- |
| **Brevo LLMs Index**               | [developers.brevo.com/llms.txt](https://developers.brevo.com/llms.txt)                                                     | Complete developer documentation directory formatted for LLM consumption |
| **Send Transactional Email Guide** | [developers.brevo.com/docs/send-a-transactional-email.md](https://developers.brevo.com/docs/send-a-transactional-email.md) | Official guide for sending transactional emails via REST                 |
| **sendTransacEmail API Reference** | [developers.brevo.com/reference/send-transac-email.md](https://developers.brevo.com/reference/send-transac-email.md)       | Full endpoint schema for parameters, headers, and responses              |
| **API Limits & Rate Throttling**   | [developers.brevo.com/docs/api-limits.md](https://developers.brevo.com/docs/api-limits.md)                                 | Rate limits, burst quotas, and HTTP 429 handling                         |
| **API Key Authentication**         | [developers.brevo.com/docs/api-key-authentication.md](https://developers.brevo.com/docs/api-key-authentication.md)         | Authentication schemes and security best practices                       |
| **Blocked Contacts API**           | [api.brevo.com/v3/smtp/blockedContacts](https://api.brevo.com/v3/smtp/blockedContacts)                                     | Endpoint to audit and manage hard-bounced or suppressed addresses        |
| **Brevo MCP Server**               | [developers.brevo.com/_mcp/server](https://developers.brevo.com/_mcp/server)                                               | Official Model Context Protocol server for AI agent integrations         |
