<?php

declare(strict_types=1);

namespace Workbench\App;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

class TestbenchEmail
{
    /**
     * Send a test email (dual-mode, HTML only, or plain text only) with full delivery details.
     *
     * @return array{
     *     recipient: string,
     *     from_address: string,
     *     from_name: string,
     *     from_display: string,
     *     mailer: string,
     *     mailer_info: string,
     *     subject: string,
     *     format: string,
     *     format_label: string,
     *     with_attachment: bool,
     *     attachment_info: string,
     *     sent_at: string,
     *     environment_info: string,
     *     html_body: string|null,
     *     text_body: string|null
     * }
     */
    public static function send(
        string $to,
        string $mailer = 'failover',
        ?string $subject = null,
        bool $withAttachment = false,
        string $format = 'both',
    ): array {
        if (! in_array($format, ['both', 'html', 'plain'], true)) {
            throw new InvalidArgumentException("Invalid format '{$format}'. Supported formats: both, html, plain.");
        }

        $fromAddress = (string) config('mail.from.address', 'hello@example.com');
        $fromName = (string) config('mail.from.name', config('app.name', 'Laravel'));
        $fromDisplay = $fromName !== '' ? "{$fromAddress} ({$fromName})" : $fromAddress;

        $subject = ($subject !== null && trim($subject) !== '')
            ? trim($subject)
            : 'Laravel Mailer Test Delivery ['.now()->format('Y-m-d H:i:s').']';

        /** @var array<string>|string $failoverChainRaw */
        $failoverChainRaw = config('mail.mailers.failover.mailers', []);
        $failoverChain = is_array($failoverChainRaw) ? $failoverChainRaw : explode(',', (string) $failoverChainRaw);

        if ($mailer === 'failover') {
            $chainText = ! empty($failoverChain) ? implode(' -> ', $failoverChain) : 'brevo, resend, smtp2go';
            $mailerInfo = "failover ({$chainText})";
        } else {
            $mailerInfo = "{$mailer} (REST API)";
        }

        $formatLabel = match ($format) {
            'html' => 'HTML Only',
            'plain' => 'Plain Text Only',
            default => 'Dual-Mode (HTML & Plain Text)',
        };

        $sentAt = now()->toIso8601String().' ('.now()->timezoneName.')';
        $attachmentInfo = $withAttachment ? 'Yes (sample-testbench-attachment.txt)' : 'None';
        $environmentInfo = app()->environment().' (Laravel '.app()->version().', PHP '.PHP_VERSION.')';

        $htmlBody = in_array($format, ['both', 'html'], true)
            ? self::renderHtml(
                to: $to,
                fromDisplay: $fromDisplay,
                mailerInfo: $mailerInfo,
                subject: $subject,
                formatLabel: $formatLabel,
                sentAt: $sentAt,
                attachmentInfo: $attachmentInfo,
                environmentInfo: $environmentInfo,
            )
            : null;

        $textBody = in_array($format, ['both', 'plain'], true)
            ? self::renderText(
                to: $to,
                fromDisplay: $fromDisplay,
                mailerInfo: $mailerInfo,
                subject: $subject,
                formatLabel: $formatLabel,
                sentAt: $sentAt,
                attachmentInfo: $attachmentInfo,
                environmentInfo: $environmentInfo,
            )
            : null;

        Mail::mailer($mailer)->send([], [], function (Message $message) use (
            $to,
            $fromAddress,
            $fromName,
            $subject,
            $htmlBody,
            $textBody,
            $withAttachment,
            $sentAt
        ): void {
            if ($fromAddress !== '') {
                $message->from($fromAddress, $fromName !== '' ? $fromName : null);
            }

            $message->to($to)->subject($subject);

            if ($htmlBody !== null) {
                $message->html($htmlBody);
            }

            if ($textBody !== null) {
                $message->text($textBody);
            }

            if ($withAttachment) {
                $attachmentData = "Laravel Mailer Test Attachment\nSent At: {$sentAt}\nRecipient: {$to}\n";
                $message->attachData($attachmentData, 'sample-testbench-attachment.txt', [
                    'mime' => 'text/plain',
                ]);
            }
        });

        return [
            'recipient' => $to,
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'from_display' => $fromDisplay,
            'mailer' => $mailer,
            'mailer_info' => $mailerInfo,
            'subject' => $subject,
            'format' => $format,
            'format_label' => $formatLabel,
            'with_attachment' => $withAttachment,
            'attachment_info' => $attachmentInfo,
            'sent_at' => $sentAt,
            'environment_info' => $environmentInfo,
            'html_body' => $htmlBody,
            'text_body' => $textBody,
        ];
    }

    /**
     * Render a clean, minimalist HTML email template.
     */
    public static function renderHtml(
        string $to,
        string $fromDisplay,
        string $mailerInfo,
        string $subject,
        string $formatLabel,
        string $sentAt,
        string $attachmentInfo,
        string $environmentInfo,
    ): string {
        $safeTo = htmlspecialchars($to, ENT_QUOTES, 'UTF-8');
        $safeFrom = htmlspecialchars($fromDisplay, ENT_QUOTES, 'UTF-8');
        $safeMailer = htmlspecialchars($mailerInfo, ENT_QUOTES, 'UTF-8');
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
        $safeFormat = htmlspecialchars($formatLabel, ENT_QUOTES, 'UTF-8');
        $safeSentAt = htmlspecialchars($sentAt, ENT_QUOTES, 'UTF-8');
        $safeAttachment = htmlspecialchars($attachmentInfo, ENT_QUOTES, 'UTF-8');
        $safeEnv = htmlspecialchars($environmentInfo, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$safeSubject}</title>
</head>
<body style="margin: 0; padding: 24px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; color: #1e293b; line-height: 1.5;">
    <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <div style="background: #4f46e5; padding: 20px 24px; color: #ffffff;">
            <h1 style="margin: 0; font-size: 18px; font-weight: 600; letter-spacing: -0.01em;">Laravel Mailer Test Delivery</h1>
            <p style="margin: 4px 0 0 0; font-size: 13px; opacity: 0.9;">{$safeFormat} test message</p>
        </div>
        <div style="padding: 24px;">
            <p style="margin: 0 0 16px 0; font-size: 14px; color: #475569;">
                This test email confirms that your Laravel Mailer configuration and transport are operating successfully.
            </p>
            <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                <tbody>
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 10px 0; font-weight: 600; color: #64748b; width: 34%;">Provider / Mailer</td>
                        <td style="padding: 10px 0; color: #0f172a; font-weight: 600;">{$safeMailer}</td>
                    </tr>
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 10px 0; font-weight: 600; color: #64748b;">Payload Format</td>
                        <td style="padding: 10px 0; color: #4f46e5; font-weight: 600;">{$safeFormat}</td>
                    </tr>
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 10px 0; font-weight: 600; color: #64748b;">Sender (From)</td>
                        <td style="padding: 10px 0; color: #0f172a;">{$safeFrom}</td>
                    </tr>
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 10px 0; font-weight: 600; color: #64748b;">Recipient (To)</td>
                        <td style="padding: 10px 0; color: #0f172a;">{$safeTo}</td>
                    </tr>
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 10px 0; font-weight: 600; color: #64748b;">Subject</td>
                        <td style="padding: 10px 0; color: #0f172a;">{$safeSubject}</td>
                    </tr>
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 10px 0; font-weight: 600; color: #64748b;">Sent At</td>
                        <td style="padding: 10px 0; color: #0f172a;">{$safeSentAt}</td>
                    </tr>
                    <tr style="border-top: 1px solid #f1f5f9;">
                        <td style="padding: 10px 0; font-weight: 600; color: #64748b;">Attachment</td>
                        <td style="padding: 10px 0; color: #0f172a;">{$safeAttachment}</td>
                    </tr>
                    <tr style="border-top: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 10px 0; font-weight: 600; color: #64748b;">Environment</td>
                        <td style="padding: 10px 0; color: #0f172a;">{$safeEnv}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div style="background: #f8fafc; padding: 14px 24px; border-top: 1px solid #f1f5f9; font-size: 11px; color: #94a3b8; text-align: center;">
            Sent by <strong>eudeka/laravel-mailer</strong> &bull; Orchestra Testbench Workbench
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Render a clean plain-text version of the email.
     */
    public static function renderText(
        string $to,
        string $fromDisplay,
        string $mailerInfo,
        string $subject,
        string $formatLabel,
        string $sentAt,
        string $attachmentInfo,
        string $environmentInfo,
    ): string {
        return <<<TEXT
==================================================
LARAVEL MAILER TEST DELIVERY
==================================================
{$formatLabel} test message.

DELIVERY DETAILS:
--------------------------------------------------
Provider / Mailer : {$mailerInfo}
Payload Format    : {$formatLabel}
Sender (From)     : {$fromDisplay}
Recipient (To)    : {$to}
Subject           : {$subject}
Sent At           : {$sentAt}
Attachment        : {$attachmentInfo}
Environment       : {$environmentInfo}
--------------------------------------------------

Sent by eudeka/laravel-mailer via Orchestra Testbench Workbench.
TEXT;
    }
}
