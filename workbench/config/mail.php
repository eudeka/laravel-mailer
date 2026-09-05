<?php

declare(strict_types=1);

return [

    'default' => env('MAIL_MAILER', 'failover'),

    'mailers' => [
        'failover' => [
            'transport' => 'failover',
            'mailers' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('MAIL_FAILOVER_MAILERS', env('FAILOVER_MAILERS', 'brevo,resend,smtp2go'))),
            ))),
        ],
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

];
