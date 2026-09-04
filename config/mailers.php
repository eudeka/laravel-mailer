<?php

declare(strict_types=1);

return [

    'resend' => [
        'transport' => 'resend',
        'key' => env('MAILER_RESEND_API_KEY', env('RESEND_API_KEY')),
        'endpoint' => env('MAILER_RESEND_ENDPOINT', env('RESEND_ENDPOINT', 'https://api.resend.com/emails')),
        'timeout' => (int) env('MAILER_RESEND_TIMEOUT', env('RESEND_TIMEOUT', 10)),
    ],

    'brevo' => [
        'transport' => 'brevo',
        'key' => env('MAILER_BREVO_API_KEY', env('BREVO_API_KEY')),
        'endpoint' => env('MAILER_BREVO_ENDPOINT', env('BREVO_ENDPOINT', 'https://api.brevo.com/v3/smtp/email')),
        'timeout' => (int) env('MAILER_BREVO_TIMEOUT', env('BREVO_TIMEOUT', 10)),
    ],

    'smtp2go' => [
        'transport' => 'smtp2go',
        'key' => env('MAILER_SMTP2GO_API_KEY', env('SMTP2GO_API_KEY')),
        'endpoint' => env('MAILER_SMTP2GO_ENDPOINT', env('SMTP2GO_ENDPOINT', 'https://api.smtp2go.com/v3/email/send')),
        'timeout' => (int) env('MAILER_SMTP2GO_TIMEOUT', env('SMTP2GO_TIMEOUT', 10)),
    ],

    'failover' => [
        'transport' => 'failover',
        'mailers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MAIL_FAILOVER_MAILERS', env('FAILOVER_MAILERS', 'brevo,resend,smtp2go'))),
        ))),
    ],

];
