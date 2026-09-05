# SMTP2GO Transport Specification

Technical specification, REST API payload mapping, and integration details for SMTP2GO in `eudeka/laravel-mailer`.

---

## 1. Overview & Authentication

- **Transport Class**: [`Eudeka\LaravelMailer\Transport\Smtp2GoApiTransport`](../../src/Transport/Smtp2GoApiTransport.php)
- **Official Website**: https://www.smtp2go.com
- **API Base URL**: `https://api.smtp2go.com/v3/email/send`
- **HTTP Method**: `POST`
- **Authentication Scheme**: Dual header authentication using `X-Smtp2go-Api-Key: {key}` and `api-key: {key}`
- **Required Headers**:
    - `X-Smtp2go-Api-Key: {key}`
    - `api-key: {key}`
    - `Idempotency-Key: {key}`
    - `Content-Type: application/json`
    - `Accept: application/json`
    - `User-Agent: eudeka-laravel-mailer/1.0`

---

## 2. Configuration & Environment Variables

| Environment Variable      | Internal Config Key (`config/mailers.php`) | Default Value                           | Description                                                      |
| :------------------------ | :----------------------------------------- | :-------------------------------------- | :--------------------------------------------------------------- |
| `MAILER_SMTP2GO_API_KEY`  | `mail.mailers.smtp2go.key`                 | `null`                                  | Primary SMTP2GO API key (falls back to `SMTP2GO_API_KEY`)        |
| `MAILER_SMTP2GO_ENDPOINT` | `mail.mailers.smtp2go.endpoint`            | `https://api.smtp2go.com/v3/email/send` | Endpoint URL (falls back to `SMTP2GO_ENDPOINT`)                  |
| `MAILER_SMTP2GO_TIMEOUT`  | `mail.mailers.smtp2go.timeout`             | `10`                                    | HTTP client timeout in seconds (falls back to `SMTP2GO_TIMEOUT`) |

---

## 3. Request Payload Schema & Mapping

SMTP2GO uses a Standard JSON API structure requiring distinct separation between file attachments and inline images, as well as an array of structured custom header objects.

### Example Request JSON Payload

```json
{
    "sender": "Acme Notifications <noreply@example.com>",
    "to": ["Jane Doe <jane@example.com>"],
    "cc": ["audit@example.com"],
    "subject": "Monthly Statement",
    "html_body": "<html><body><h1>Your Statement</h1><img src=\"cid:graph.png\" /></body></html>",
    "text_body": "Your Statement\nPlease find attached.",
    "attachments": [
        {
            "filename": "statement.pdf",
            "fileblob": "JVBERi0xLjQKJ...",
            "mimetype": "application/pdf"
        }
    ],
    "inlines": [
        {
            "filename": "graph.png",
            "fileblob": "iVBORw0KGgo...",
            "mimetype": "image/png"
        }
    ],
    "custom_headers": [
        {
            "header": "Reply-To",
            "value": "Support <support@example.com>"
        },
        {
            "header": "In-Reply-To",
            "value": "<orig-msg-123@example.com>"
        },
        {
            "header": "X-Tag",
            "value": "statements"
        },
        {
            "header": "X-Metadata-Account-ID",
            "value": "acc_5510"
        }
    ],
    "fastaccept": false
}
```

### Field Mapping Rules

- **Sender (`sender`)**: Formatted as an RFC-5322 string `Name <email>` or plain email.
- **Recipients (`to`, `cc`, `bcc`)**: Arrays of RFC-5322 formatted address strings.
- **Content (`html_body`, `text_body`)**: HTML placed in `html_body`. Plain-text fallback auto-generated via `resolvePlainTextBody()` and passed in `text_body`.
- **Inline CIDs (`inlines`)**: Embedded images (`$message->embed()`) are placed in a dedicated `inlines` array, with `fileblob` containing Base64 data and `filename` matching the HTML `cid:<filename>` reference.
- **Attachments (`attachments`)**: Physical file attachments are placed in `attachments` using keys `filename`, `fileblob`, and `mimetype`.
- **Custom Headers & Reply-To**: Reply-To, In-Reply-To, References, tags, and metadata are mapped into an array of objects `[{"header": "...", "value": "..."}]`. Standard MIME control headers (`From`, `To`, `Subject`, `Content-Type`, `MIME-Version`) are filtered out to avoid payload rejections.
- **Synchronous Delivery (`fastaccept: false`)**: Enforces real-time recipient validation by the SMTP2GO gateway, preventing asynchronous false-positive acceptance.

---

## 4. Response Schema & Silent Failure Detection

### Successful Response (HTTP 200 OK)

```json
{
    "request_id": "req_88492019482",
    "data": {
        "succeeded": 1,
        "failed": 0,
        "failures": [],
        "email_id": "emsg_88492019482_abcdef"
    }
}
```

### Silent Failure Detection & Error Parsing

SMTP2GO's REST API is notorious for returning `HTTP 200 OK` even when email delivery has failed. `Smtp2GoApiTransport` rigorously detects these silent failures:

1. **Failure Payload Envelope**: If the HTTP response is 200 OK, but:
    - `data` is `null` or missing,
    - `data.succeeded === 0`,
    - `data.failed > 0`,
    - `data.failures` contains recipient emails,
    - `data.email_id` is null or missing,
    - or an `error` or `error_code` property is returned,
      a `TransportException` is thrown immediately.
2. **Failover Acceleration**: Converting silent 200 OK failures into `TransportException` instances allows Laravel's `FailoverTransport` to switch seamlessly to fallback providers (Brevo or Resend).
3. **HTTP 4xx/5xx Error Schema**:
    ```json
    {
        "request_id": "req_88492019482",
        "error": "Endpoint rate limit exceeded",
        "error_code": "E_RATE_LIMIT"
    }
    ```
    Formatted into: `Failed sending email via SMTP2GO (HTTP 429): Endpoint rate limit exceeded`.

---

## 5. Quirks, Edge Cases & Account Constraints

| Edge Case / Condition               | SMTP2GO Behavior                                                                               | Transport Mitigation                                                                                |
| :---------------------------------- | :--------------------------------------------------------------------------------------------- | :-------------------------------------------------------------------------------------------------- |
| **Silent Failures on HTTP 200**     | Returns HTTP 200 with `data.failed > 0` for invalid recipients or unverified senders.          | Explicitly inspects `data.failed`, `data.failures`, and `data.succeeded` before accepting the send. |
| **Separate Inlines vs Attachments** | Rejects CID images if included in standard `attachments`.                                      | Categorizes attachments: inline parts go to `inlines`, standard files go to `attachments`.          |
| **Forbidden MIME Headers**          | Returns HTTP 400 if `custom_headers` contains reserved headers (`Content-Type`, `From`, `To`). | Filtered automatically in `ExtractsEmailData::extractHeadersTagsAndMetadata()`.                     |
| **Rate Limiting & Throttling**      | Bursts exceeding account limits trigger 1-minute blocks with HTTP 429.                         | Reads `Retry-After` header and includes wait time in `TransportException`.                          |
| **Sender Domain Verification**      | Emails from unverified domains fail during API dispatch.                                       | Failover instantly triggers next configured provider.                                               |

---

## 6. Official Documentation & AI/LLM Resources

| Resource                                       | URL                                                                                                                                        | Description                                                                |
| :--------------------------------------------- | :----------------------------------------------------------------------------------------------------------------------------------------- | :------------------------------------------------------------------------- |
| **SMTP2GO LLMs Index**                         | [developers.smtp2go.com/llms.txt](https://developers.smtp2go.com/llms.txt)                                                                 | Complete developer documentation directory formatted for LLM consumption   |
| **REST API Introduction**                      | [developers.smtp2go.com/docs/introduction-guide.md](https://developers.smtp2go.com/docs/introduction-guide.md)                             | Architecture overview of the v3 REST API JSON format                       |
| **Standard Email Endpoint (`/v3/email/send`)** | [developers.smtp2go.com/reference/send-standard-email.md](https://developers.smtp2go.com/reference/send-standard-email.md)                 | Full endpoint schema for sender, recipients, inlines, and attachments      |
| **Adding Attachments & Inlines**               | [developers.smtp2go.com/docs/adding-attachments.md](https://developers.smtp2go.com/docs/adding-attachments.md)                             | Base64 `fileblob` conversion, MIME types, and `cid:<filename>` mapping     |
| **Response Codes & Error Handling**            | [developers.smtp2go.com/docs/response-codes.md](https://developers.smtp2go.com/docs/response-codes.md)                                     | Status codes (200–500), `E_ApiResponseCodes`, and validation error schemas |
| **Rate Limiting & Throttling**                 | [developers.smtp2go.com/docs/rate-limiting.md](https://developers.smtp2go.com/docs/rate-limiting.md)                                       | IP burst limits, API key throttles, and temporary 1-minute blocks          |
| **Sender Domain Verification**                 | [developers.smtp2go.com/docs/getting-started#sender-verification](https://developers.smtp2go.com/docs/getting-started#sender-verification) | Sender verification, SPF, and DKIM configuration requirements              |
