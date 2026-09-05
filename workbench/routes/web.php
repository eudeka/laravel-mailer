<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Workbench\App\TestbenchEmail;

Route::match(['GET', 'POST'], '/', function (Request $request) {
    $fromAddress = (string) config('mail.from.address');
    $fromName = (string) config('mail.from.name');

    $brevoKey = (string) (config('mail.mailers.brevo.key') ?? config('mail.mailers.brevo.api_key') ?? '');
    $resendKey = (string) (config('mail.mailers.resend.key') ?? config('mail.mailers.resend.api_key') ?? '');
    $smtp2goKey = (string) (config('mail.mailers.smtp2go.key') ?? config('mail.mailers.smtp2go.api_key') ?? '');
    $failoverChainRaw = config('mail.mailers.failover.mailers', []);
    $failoverChain = is_array($failoverChainRaw) ? $failoverChainRaw : explode(',', (string) $failoverChainRaw);

    $status = null;
    $error = null;
    $details = null;

    $selectedFormat = (string) $request->input('format', 'both');

    if ($request->isMethod('POST') || $request->has('send')) {
        $to = trim((string) $request->input('to'));
        $selectedMailer = trim((string) ($request->input('mailer') ?: config('mail.default', 'failover')));
        $subject = trim((string) $request->input('subject'));
        $format = trim((string) $request->input('format', 'both'));
        $withAttachment = (bool) $request->input('with_attachment');

        if (! in_array($format, ['both', 'html', 'plain'], true)) {
            $error = "Invalid format '{$format}'. Supported formats: both, html, plain.";
        } elseif ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid or empty recipient email address.';
        } else {
            try {
                $result = TestbenchEmail::send(
                    to: $to,
                    mailer: $selectedMailer,
                    subject: $subject !== '' ? $subject : null,
                    withAttachment: $withAttachment,
                    format: $format,
                );

                $status = "Email successfully sent to {$to} using mailer [{$selectedMailer}] ({$result['format_label']})!";
                $details = [
                    'provider' => $result['mailer_info'],
                    'format' => $result['format_label'],
                    'from' => $result['from_display'],
                    'recipient' => $result['recipient'],
                    'subject' => $result['subject'],
                    'attachment' => $result['attachment_info'],
                    'sent_at' => $result['sent_at'],
                    'environment' => $result['environment_info'],
                ];
            } catch (Throwable $e) {
                $error = $e->getMessage();
                $details = [
                    'exception' => get_class($e),
                    'file' => $e->getFile().':'.$e->getLine(),
                ];
            }
        }

        if ($request->wantsJson() || $request->input('format') === 'json') {
            return response()->json([
                'success' => $error === null,
                'status' => $status,
                'error' => $error,
                'details' => $details,
            ], $error === null ? 200 : 500);
        }
    }

    $mask = static fn (string $k): string => $k !== '' ? 'Configured ('.substr($k, 0, 3).'***)' : 'Not Configured';

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laravel Mailer - Workbench Test UI</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen py-10 px-4">
    <div class="max-w-2xl mx-auto bg-white rounded-xl shadow p-6 sm:p-8 border border-slate-200">
        <div class="flex items-center space-x-3 mb-6 pb-4 border-b border-slate-200">
            <span class="text-3xl">✉️</span>
            <div>
                <h1 class="text-xl font-bold text-slate-800">Laravel Mailer Workbench</h1>
                <p class="text-xs text-slate-500">Real REST API email delivery testing for Brevo, Resend, SMTP2GO & Failover</p>
            </div>
        </div>

        <!-- Driver & Sender Status Card -->
        <div class="mb-6 bg-slate-100 rounded-lg p-4 text-xs font-mono space-y-1.5">
            <div class="font-bold text-slate-700 uppercase mb-2">Configuration Overview:</div>
            <div class="flex justify-between"><span>Sender (From):</span> <span class="text-slate-800 font-semibold">'.htmlspecialchars($fromAddress.' ('.$fromName.')').'</span></div>
            <div class="flex justify-between"><span>Brevo API Key:</span> <span class="'.($brevoKey !== '' ? 'text-emerald-600 font-semibold' : 'text-rose-500').'">'.$mask($brevoKey).'</span></div>
            <div class="flex justify-between"><span>Resend API Key:</span> <span class="'.($resendKey !== '' ? 'text-emerald-600 font-semibold' : 'text-rose-500').'">'.$mask($resendKey).'</span></div>
            <div class="flex justify-between"><span>SMTP2GO API Key:</span> <span class="'.($smtp2goKey !== '' ? 'text-emerald-600 font-semibold' : 'text-rose-500').'">'.$mask($smtp2goKey).'</span></div>
            <div class="flex justify-between pt-1 border-t border-slate-200"><span>Failover Chain:</span> <span class="text-indigo-600 font-semibold">'.htmlspecialchars(implode(' &rarr; ', $failoverChain)).'</span></div>
        </div>';

    if ($status !== null) {
        $html .= '<div class="mb-6 p-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
            <div class="font-bold flex items-center gap-1.5"><span>✓</span> '.htmlspecialchars($status).'</div>
            '.($details ? '<pre class="mt-2 text-xs bg-emerald-100/60 p-2 rounded overflow-x-auto font-mono">'.htmlspecialchars((string) json_encode($details, JSON_PRETTY_PRINT)).'</pre>' : '').'
        </div>';
    }

    if ($error !== null) {
        $html .= '<div class="mb-6 p-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-sm">
            <div class="font-bold flex items-center gap-1.5"><span>✕</span> Delivery Failed:</div>
            <div class="mt-1">'.htmlspecialchars($error).'</div>
            '.($details ? '<pre class="mt-2 text-xs bg-rose-100/60 p-2 rounded overflow-x-auto font-mono">'.htmlspecialchars((string) json_encode($details, JSON_PRETTY_PRINT)).'</pre>' : '').'
        </div>';
    }

    $html .= '
        <form method="POST" action="/" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Recipient Email</label>
                <input type="email" name="to" required placeholder="recipient@example.com" class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm" value="'.htmlspecialchars((string) $request->input('to')).'">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Mailer Driver</label>
                    <select name="mailer" class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                        <option value="failover" '.($request->input('mailer') === 'failover' ? 'selected' : '').'>failover (Default Failover Chain)</option>
                        <option value="resend" '.($request->input('mailer') === 'resend' ? 'selected' : '').'>resend (Resend REST API)</option>
                        <option value="brevo" '.($request->input('mailer') === 'brevo' ? 'selected' : '').'>brevo (Brevo REST API)</option>
                        <option value="smtp2go" '.($request->input('mailer') === 'smtp2go' ? 'selected' : '').'>smtp2go (SMTP2GO REST API)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Payload Format</label>
                    <select name="format" class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                        <option value="both" '.($selectedFormat === 'both' ? 'selected' : '').'>both (Dual-Mode: HTML &amp; Plain Text)</option>
                        <option value="html" '.($selectedFormat === 'html' ? 'selected' : '').'>html (HTML Only)</option>
                        <option value="plain" '.($selectedFormat === 'plain' ? 'selected' : '').'>plain (Plain Text Only)</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1">Subject</label>
                <input type="text" name="subject" placeholder="Optional subject..." class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm" value="'.htmlspecialchars((string) $request->input('subject')).'">
            </div>

            <div class="pt-2">
                <label class="inline-flex items-center text-sm text-slate-700 cursor-pointer">
                    <input type="checkbox" name="with_attachment" value="1" '.($request->input('with_attachment') ? 'checked' : '').' class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 h-4 w-4">
                    <span class="ml-2">Include sample file attachment (test REST MIME attachment)</span>
                </label>
            </div>

            <div class="pt-2">
                <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2.5 px-4 rounded-md shadow transition">
                    Send Test Email
                </button>
            </div>
        </form>
    </div>
</body>
</html>';

    return response($html);
});
