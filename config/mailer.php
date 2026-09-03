<?php

declare(strict_types=1);

return [

    'resend' => [
        'transport' => 'resend',
        'key' => env('RESEND_API_KEY'),
        'endpoint' => env('RESEND_ENDPOINT', 'https://api.resend.com/emails'),
        'timeout' => (int) env('RESEND_TIMEOUT', 10),
    ],

    'brevo' => [
        'transport' => 'brevo',
        'key' => env('BREVO_API_KEY'),
        'endpoint' => env('BREVO_ENDPOINT', 'https://api.brevo.com/v3/smtp/email'),
        'timeout' => (int) env('BREVO_TIMEOUT', 10),
    ],

    'smtp2go' => [
        'transport' => 'smtp2go',
        'key' => env('SMTP2GO_API_KEY'),
        'endpoint' => env('SMTP2GO_ENDPOINT', 'https://api.smtp2go.com/v3/email/send'),
        'timeout' => (int) env('SMTP2GO_TIMEOUT', 10),
    ],

    'failover_mailers' => env('FAILOVER_MAILERS'),

];
