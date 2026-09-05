<?php

declare(strict_types=1);

use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;
use Workbench\App\TestbenchEmail;

Artisan::command('mail:test
    {to? : Recipient email address (e.g. user@example.com)}
    {--mailer= : Mailer driver to use (default: failover)}
    {--format=both : Email payload format (both, html, plain)}
    {--subject= : Custom email subject}
    {--with-attachment : Include a sample in-memory text attachment}
    {--diagnose : Display driver configuration status and failover chain}', function () {
    /** @var ClosureCommand $this */
    $diagnoseOnly = (bool) $this->option('diagnose');
    $to = (string) $this->argument('to');

    $fromAddress = (string) config('mail.from.address');
    $fromName = (string) config('mail.from.name');

    $defaultMailer = (string) config('mail.default', 'failover');
    $selectedMailer = (string) ($this->option('mailer') ?: $defaultMailer);

    $brevoKey = (string) (config('mail.mailers.brevo.key') ?? config('mail.mailers.brevo.api_key') ?? '');
    $resendKey = (string) (config('mail.mailers.resend.key') ?? config('mail.mailers.resend.api_key') ?? '');
    $smtp2goKey = (string) (config('mail.mailers.smtp2go.key') ?? config('mail.mailers.smtp2go.api_key') ?? '');

    $formatStatus = static function (string $key): string {
        if ($key === '') {
            return '<fg=red>NOT CONFIGURED</>';
        }
        $len = strlen($key);
        $preview = $len <= 8 ? substr($key, 0, 2).'***' : substr($key, 0, 4).'***'.substr($key, -4);

        return "<fg=green>CONFIGURED</> ({$preview})";
    };

    /** @var array<string>|string $failoverChainRaw */
    $failoverChainRaw = config('mail.mailers.failover.mailers', []);
    $failoverChain = is_array($failoverChainRaw) ? $failoverChainRaw : explode(',', (string) $failoverChainRaw);

    $this->line('<fg=cyan;options=bold>Laravel Mailer Diagnostics</>');
    $this->line('  <fg=gray>From Address :</> '.$fromAddress.' ('.$fromName.')');
    $this->line('  <fg=gray>Default      :</> '.$defaultMailer);
    $this->line('  <fg=gray>Active Driver:</> '.$selectedMailer);
    $this->line('  <fg=gray>Failover     :</> '.implode(' -> ', $failoverChain));
    $this->line('');
    $this->line('<fg=gray;options=bold>Configured Transports:</>');
    $this->line('  - <fg=yellow>brevo</>   : '.$formatStatus($brevoKey).' ['.config('mail.mailers.brevo.endpoint', 'https://api.brevo.com/v3/smtp/email').']');
    $this->line('  - <fg=yellow>resend</>  : '.$formatStatus($resendKey).' ['.config('mail.mailers.resend.endpoint', 'https://api.resend.com/emails').']');
    $this->line('  - <fg=yellow>smtp2go</> : '.$formatStatus($smtp2goKey).' ['.config('mail.mailers.smtp2go.endpoint', 'https://api.smtp2go.com/v3/email/send').']');
    $this->line('');

    if ($diagnoseOnly && $to === '') {
        $this->line('<fg=gray>Run with recipient to send test email: vendor/bin/testbench mail:test user@example.com</>');

        return 0;
    }

    if ($to === '') {
        $input = $this->ask('Recipient email address');
        $to = is_string($input) ? trim($input) : '';
    }

    if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $this->line('<fg=red>[ERROR]</> Invalid recipient email address.');

        return 1;
    }

    $format = (string) ($this->option('format') ?: 'both');

    if (! in_array($format, ['both', 'html', 'plain'], true)) {
        $this->line('<fg=red>[ERROR]</> Invalid format \''.$format.'\'. Supported formats: both, html, plain.');

        return 1;
    }

    $withAttachment = (bool) $this->option('with-attachment');
    $subject = (string) ($this->option('subject') ?: 'Laravel Mailer Test Delivery ['.now()->format('Y-m-d H:i:s').']');

    $fromDisplay = $fromName !== '' ? "{$fromAddress} ({$fromName})" : $fromAddress;
    $mailerInfo = $selectedMailer === 'failover'
        ? 'failover ('.(! empty($failoverChain) ? implode(' -> ', $failoverChain) : 'brevo, resend, smtp2go').')'
        : "{$selectedMailer} (REST API)";

    $formatLabel = match ($format) {
        'html' => 'HTML Only',
        'plain' => 'Plain Text Only',
        default => 'Dual-Mode (HTML & Plain Text)',
    };

    $this->line('<fg=cyan;options=bold>Laravel Mailer Test Delivery</>');
    $this->line('  <fg=gray>Recipient (To)  :</> <fg=cyan>'.$to.'</>');
    $this->line('  <fg=gray>Sender (From)   :</> '.$fromDisplay);
    $this->line('  <fg=gray>Provider/Mailer :</> <fg=yellow>'.$mailerInfo.'</>');
    $this->line('  <fg=gray>Payload Format  :</> <fg=magenta>'.$formatLabel.'</>');
    $this->line('  <fg=gray>Subject         :</> '.$subject);
    $this->line('  <fg=gray>Attachment      :</> '.($withAttachment ? '<fg=yellow>Yes (sample-testbench-attachment.txt)</>' : 'None'));
    $this->line('');
    $this->line('Sending email via <fg=yellow>'.$selectedMailer.'</>...');

    try {
        $result = TestbenchEmail::send(
            to: $to,
            mailer: $selectedMailer,
            subject: $subject,
            withAttachment: $withAttachment,
            format: $format,
        );

        $this->line('');
        $this->line('<fg=green;options=bold>[OK]</> Email successfully delivered to <fg=cyan>'.$to.'</> via <fg=yellow>'.$selectedMailer.'</>!');
        $this->line('  <fg=gray>Sent At         :</> '.$result['sent_at']);
        $this->line('  <fg=gray>Provider Detail :</> '.$result['mailer_info']);
        $this->line('  <fg=gray>Payload Format  :</> '.$result['format_label']);
        $this->line('  <fg=gray>Environment     :</> '.$result['environment_info']);

        return 0;
    } catch (Throwable $e) {
        $this->line('');
        $this->line('<fg=red;options=bold>[ERROR]</> Email delivery failed via <fg=yellow>'.$selectedMailer.'</>:');
        $this->line('  '.$e->getMessage());

        if ($selectedMailer === 'failover') {
            $this->line('  <fg=gray>(Note: All transports in failover chain either failed or are unconfigured)</>');
        }

        return 1;
    }
})->purpose('Test real email delivery across Laravel Mailer drivers (Brevo, Resend, SMTP2GO, or Failover)');
