<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->tempDir = sys_get_temp_dir().'/laravel_mailer_test_'.uniqid();
    $this->files->makeDirectory($this->tempDir, 0755, true);

    $this->tempConfigFile = $this->tempDir.'/mail.php';
    $this->tempEnvExample = $this->tempDir.'/.env.example';
    $this->tempEnv = $this->tempDir.'/.env';
});

afterEach(function () {
    if (isset($this->files) && $this->files->isDirectory($this->tempDir)) {
        $this->files->deleteDirectory($this->tempDir);
    }
});

it('updates config/mail.php and appends environment variables to .env and .env.example', function () {
    $initialMailConfig = <<<'PHP'
<?php

return [
    'default' => env('MAIL_MAILER', 'log'),
    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
        ],
        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
        ],
    ],
];
PHP;

    $this->files->put($this->tempConfigFile, $initialMailConfig);
    $this->files->put($this->tempEnvExample, "APP_NAME=Laravel\n");
    $this->files->put($this->tempEnv, "APP_NAME=Laravel\n");

    $status = Artisan::call('eudeka:mailer-install', [
        '--config' => $this->tempConfigFile,
        '--env' => $this->tempEnv,
        '--example-env' => $this->tempEnvExample,
    ]);

    expect($status)->toBe(0);

    $updatedConfig = $this->files->get($this->tempConfigFile);
    expect($updatedConfig)->toContain('MAIL_FAILOVER_MAILERS')
        ->and($updatedConfig)->toContain('brevo,resend,smtp2go');

    $updatedEnvExample = $this->files->get($this->tempEnvExample);
    expect($updatedEnvExample)->toContain('MAIL_MAILER=failover')
        ->and($updatedEnvExample)->toContain('MAILER_BREVO_API_KEY=')
        ->and($updatedEnvExample)->toContain('MAILER_RESEND_API_KEY=')
        ->and($updatedEnvExample)->toContain('MAILER_SMTP2GO_API_KEY=');

    $updatedEnv = $this->files->get($this->tempEnv);
    expect($updatedEnv)->toContain('MAIL_MAILER=failover')
        ->and($updatedEnv)->toContain('MAIL_FAILOVER_MAILERS=brevo,resend,smtp2go');
});

it('skips updating if configuration already contains MAIL_FAILOVER_MAILERS', function () {
    $alreadyConfigured = <<<'PHP'
<?php

return [
    'mailers' => [
        'failover' => [
            'transport' => 'failover',
            'mailers' => explode(',', env('MAIL_FAILOVER_MAILERS')),
        ],
    ],
];
PHP;

    $this->files->put($this->tempConfigFile, $alreadyConfigured);
    $this->files->put($this->tempEnvExample, "MAIL_FAILOVER_MAILERS=brevo\n");

    $status = Artisan::call('eudeka:mailer-install', [
        '--config' => $this->tempConfigFile,
        '--example-env' => $this->tempEnvExample,
    ]);

    expect($status)->toBe(0);

    expect($this->files->get($this->tempConfigFile))->toBe($alreadyConfigured)
        ->and($this->files->get($this->tempEnvExample))->toBe("MAIL_FAILOVER_MAILERS=brevo\n");
});

it('injects failover configuration when mailers array exists without existing failover block', function () {
    $mailConfigWithoutFailover = <<<'PHP'
<?php

return [
    'default' => 'smtp',
    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
        ],
    ],
];
PHP;

    $this->files->put($this->tempConfigFile, $mailConfigWithoutFailover);

    $status = Artisan::call('eudeka:mailer-install', [
        '--config' => $this->tempConfigFile,
        '--env' => $this->tempEnv,
        '--example-env' => $this->tempEnvExample,
    ]);

    expect($status)->toBe(0);

    $updatedConfig = $this->files->get($this->tempConfigFile);
    expect($updatedConfig)->toContain("'failover' => [")
        ->and($updatedConfig)->toContain('MAIL_FAILOVER_MAILERS');
});

it('gracefully skips updating when config file does not exist', function () {
    $nonExistentConfig = $this->tempDir.'/non_existent_mail.php';

    $status = Artisan::call('eudeka:mailer-install', [
        '--config' => $nonExistentConfig,
        '--env' => $this->tempEnv,
        '--example-env' => $this->tempEnvExample,
    ]);

    expect($status)->toBe(0);
});
