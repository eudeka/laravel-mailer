# Resend Transport Specification

Technical specification, REST API payload mapping, and integration details for Resend in `eudeka/laravel-mailer`.

---

## 1. Overview & Authentication

- **Transport Class**: [`Eudeka\LaravelMailer\Transport\ResendApiTransport`](../../src/Transport/ResendApiTransport.php)
- **Official Website**: https://resend.com
- **API Base URL**: `https://api.resend.com/emails`
- **HTTP Method**: `POST`
- **Authentication Scheme**: Bearer token via `Authorization: Bearer {key}`
- **Required Headers**:
    - `Authorization: Bearer {key}`
    - `Idempotency-Key: {key}`
    - `Content-Type: application/json`
    - `Accept: application/json`
    - `User-Agent: eudeka-laravel-mailer/1.0` _(Essential: bypasses Cloudflare WAF Error 1010)_

---

## 2. Configuration & Environment Variables

| Environment Variable     | Internal Config Key (`config/mailers.php`) | Default Value                   | Description                                                     |
| :----------------------- | :----------------------------------------- | :------------------------------ | :-------------------------------------------------------------- |
| `MAILER_RESEND_API_KEY`  | `mail.mailers.resend.key`                  | `null`                          | Primary Resend API key (falls back to `RESEND_API_KEY`)         |
| `MAILER_RESEND_ENDPOINT` | `mail.mailers.resend.endpoint`             | `https://api.resend.com/emails` | Endpoint URL (falls back to `RESEND_ENDPOINT`)                  |
| `MAILER_RESEND_TIMEOUT`  | `mail.mailers.resend.timeout`              | `10`                            | HTTP client timeout in seconds (falls back to `RESEND_TIMEOUT`) |

---

## 3. Request Payload Schema & Mapping

Resend accepts an RFC-5322 formatted JSON schema with dedicated support for inline CIDs, scheduled delivery, and topic subscription IDs.

### Example Request JSON Payload

```json
{
    "from": "Acme Notifications <noreply@example.com>",
    "to": ["Jane Doe <jane@example.com>"],
    "cc": ["audit@example.com"],
    "reply_to": ["Support <support@example.com>"],
    "subject": "Your Subscription Receipt",
    "html": "<html><body><h1>Thank You</h1><img src=\"cid:logo.png\" /></body></html>",
    "text": "Thank You\nYour subscription receipt.",
    "attachments": [
        {
            "filename": "receipt.pdf",
            "content": "JVBERi0xLjQKJ...",
            "content_type": "application/pdf"
        },
        {
            "filename": "logo.png",
            "content": "iVBORw0KGgo...",
            "content_type": "image/png",
            "content_id": "logo.png"
        }
    ],
    "tags": [
        {
            "name": "category",
            "value": "billing"
        },
        {
            "name": "customer_id",
            "value": "cust_9941"
        }
    ],
    "headers": {
        "X-Entity-Ref-ID": "ref_8829"
    },
    "scheduled_at": "2026-09-06T12:00:00Z",
    "topic_id": "topic_newsletters"
}
```

### Field Mapping Rules

- **Sender (`from`)**: RFC-5322 string `Name <email>` or plain email string.
- **Recipients (`to`, `cc`, `bcc`)**: Array of RFC-5322 strings.
- **Reply-To (`reply_to`)**: Array of RFC-5322 strings.
- **Content (`html`, `text`)**: HTML passed in `html`. Plain-text fallback auto-generated from HTML via `resolvePlainTextBody()` if omitted.
- **Attachments & Inline CIDs**: Both file attachments and embedded images are passed in the `attachments` array. Embedded images (`$message->embed()`) specify `content_id` to link with `<img src="cid:...">` in the HTML body.
- **Tags & Metadata**: Mapped to an array of objects `[{"name": "...", "value": "..."}]`. Keys are sanitized to `[a-zA-Z0-9_-]` and truncated to 256 characters to satisfy Resend's validation.
- **Scheduled Sending**: Extracted from `X-Scheduled-At` or `Scheduled-At` headers, assigned to root attribute `scheduled_at`, and stripped from outgoing MIME headers.
- **Topics & Subscriptions**: Extracted from `X-Topic-Id` or `Topic-Id` headers and assigned to root attribute `topic_id`.
- **Idempotency Key**: Passed via the HTTP request header `Idempotency-Key`.

---

## 4. Response Schema & Silent Failure Detection

### Successful Response (HTTP 200 OK)

```json
{
    "id": "49a3999c-0ce1-4ea6-ab68-afcd6dc2e794"
}
```

### Silent Failure Detection & Error Parsing

`ResendApiTransport` validates the following conditions:

1. **HTTP Status**: Any response with status `< 200` or `>= 300` throws a `TransportException`.
2. **Missing `id`**: If the HTTP status is 2xx but `id` is null, empty string, or missing, a `TransportException` is thrown.
3. **Envelope Error Payloads**: Resend occasionally returns HTTP 200 with an error object:
    ```json
    {
        "data": null,
        "error": {
            "statusCode": 422,
            "name": "validation_error",
            "message": "Invalid recipient email"
        }
    }
    ```
    Detected and converted into `TransportException`.
4. **Standard Error Schema**:
    ```json
    {
        "statusCode": 422,
        "name": "invalid_from_address",
        "message": "The from address does not match your verified domain."
    }
    ```
    Formatted into: `Failed sending email via Resend (HTTP 422): invalid_from_address: The from address does not match your verified domain.`.

---

## 5. Quirks, Edge Cases & Account Constraints

| Edge Case / Condition                  | Resend Behavior                                                               | Transport Mitigation                                                           |
| :------------------------------------- | :---------------------------------------------------------------------------- | :----------------------------------------------------------------------------- |
| **Cloudflare HTTP 403 (Error 1010)**   | Cloudflare blocks requests with missing or generic user agents.               | Enforce `'User-Agent' => 'eudeka-laravel-mailer/1.0'` on all requests.         |
| **Domain Verification (HTTP 403/422)** | Sending from unverified domain results in immediate rejection.                | Throws `TransportException` to trigger instant failover to secondary provider. |
| **Tag Name Constraints**               | Rejects tags containing spaces or special characters outside `[a-zA-Z0-9_-]`. | Sanitized using `preg_replace('/[^a-zA-Z0-9_-]/', '_', $name)`.                |
| **Rate Limiting (HTTP 429)**           | Default quota is 10 requests per second.                                      | Parses `Retry-After` header and includes delay duration in exception.          |
| **Idempotency Conflicts (HTTP 409)**   | Returns HTTP 409 on `concurrent_idempotent_requests`.                         | Exception captures error name and informs queue worker to retry cleanly.       |
| **Attachment Size Limit**              | 40MB total message size ceiling.                                              | Handled gracefully; failover routes to backup if size limits are violated.     |

---

## 6. Official Documentation & AI/LLM Resources

| Resource                       | URL                                                                                                              | Description                                                           |
| :----------------------------- | :--------------------------------------------------------------------------------------------------------------- | :-------------------------------------------------------------------- |
| **Resend LLMs Index**          | [resend.com/docs/llms.txt](https://resend.com/docs/llms.txt)                                                     | Complete sitemap and documentation index optimized for AI consumption |
| **Send Email API Reference**   | [resend.com/docs/api-reference/emails/send-email.md](https://resend.com/docs/api-reference/emails/send-email.md) | Full endpoint schema for payload fields, attachments, and headers     |
| **Errors Reference**           | [resend.com/docs/api-reference/errors.md](https://resend.com/docs/api-reference/errors.md)                       | Official error codes, status codes (400–503), and error types         |
| **Rate Limits & Quotas**       | [resend.com/docs/api-reference/rate-limit.md](https://resend.com/docs/api-reference/rate-limit.md)               | 10 req/s limits, burst quotas, and `Retry-After` header behavior      |
| **Manage Emails & Lifecycle**  | [resend.com/docs/dashboard/emails/manage-emails.md](https://resend.com/docs/dashboard/emails/manage-emails.md)   | Email status lifecycle (`sent`, `delivered`, `bounced`, `suppressed`) |
| **Cloudflare 403 Error 1010**  | [resend.com/docs/knowledge-base/403-error-1010.md](https://resend.com/docs/knowledge-base/403-error-1010.md)     | Root cause and User-Agent resolution for WAF blocks                   |
| **Webhooks: Email Suppressed** | [resend.com/docs/webhooks/emails/suppressed.md](https://resend.com/docs/webhooks/emails/suppressed.md)           | Webhook payload structure for hard bounces and spam complaints        |
