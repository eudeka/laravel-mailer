<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class MailerInstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'eudeka:mailer-install
        {--config= : Custom path to config/mail.php}
        {--env= : Custom path to .env file}
        {--example-env= : Custom path to .env.example file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Configure host application config/mail.php and environment variables for Eudeka Laravel Mailer';

    public function __construct(
        private readonly Filesystem $files = new Filesystem,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->components->info('Installing Eudeka Laravel Mailer...');

        $configOption = $this->option('config');
        $configPath = is_string($configOption) && $configOption !== ''
            ? $configOption
            : config_path('mail.php');

        $this->updateMailConfiguration($configPath);

        $envOption = $this->option('env');
        $envPath = is_string($envOption) && $envOption !== ''
            ? $envOption
            : base_path('.env');

        $exampleEnvOption = $this->option('example-env');
        $exampleEnvPath = is_string($exampleEnvOption) && $exampleEnvOption !== ''
            ? $exampleEnvOption
            : base_path('.env.example');

        $this->updateEnvironmentFiles([$exampleEnvPath, $envPath]);

        $this->components->info('Eudeka Laravel Mailer installation completed successfully.');

        return self::SUCCESS;
    }

    /**
     * Update host config/mail.php to ensure failover uses MAIL_FAILOVER_MAILERS.
     */
    private function updateMailConfiguration(string $configPath): void
    {
        if (! $this->files->exists($configPath)) {
            $this->components->warn(sprintf('Config file [%s] does not exist. Skipping mail config update.', $configPath));

            return;
        }

        $content = $this->files->get($configPath);

        if (str_contains($content, 'MAIL_FAILOVER_MAILERS')) {
            $this->components->twoColumnDetail('config/mail.php', '<fg=yellow;options=bold>ALREADY CONFIGURED</>');

            return;
        }

        $failoverReplacement = <<<'PHP'
        'failover' => [
            'transport' => 'failover',
            'mailers' => explode(',', (string) env('MAIL_FAILOVER_MAILERS', 'brevo,resend,smtp2go')),
        ],
PHP;

        // Pattern 1: Replace existing 'failover' array block
        $pattern = "/'failover'\s*=>\s*\[\s*'transport'\s*=>\s*'failover'[\s\S]*?'mailers'\s*=>\s*\[[\s\S]*?\]\s*,?\s*\]\s*,?/";

        if (preg_match($pattern, $content)) {
            $updatedContent = preg_replace($pattern, $failoverReplacement, $content, 1);

            if (is_string($updatedContent)) {
                $this->files->put($configPath, $updatedContent);
                $this->components->twoColumnDetail('config/mail.php failover configuration', '<fg=green;options=bold>UPDATED</>');

                return;
            }
        }

        // Pattern 2: If 'mailers' => [ is present, inject failover block inside mailers array
        $mailersArrayPattern = "/('mailers'\s*=>\s*\[)/";

        if (preg_match($mailersArrayPattern, $content)) {
            $injection = "$1\n".$failoverReplacement;
            $updatedContent = preg_replace($mailersArrayPattern, $injection, $content, 1);

            if (is_string($updatedContent)) {
                $this->files->put($configPath, $updatedContent);
                $this->components->twoColumnDetail('config/mail.php injected failover block', '<fg=green;options=bold>UPDATED</>');

                return;
            }
        }

        $this->components->warn('Unable to automatically patch config/mail.php. Please configure mailers.failover manually.');
    }

    /**
     * Append sample environment variables to .env and .env.example if missing.
     *
     * @param  array<string>  $envFiles
     */
    private function updateEnvironmentFiles(array $envFiles): void
    {
        $envSnippet = "\n# Eudeka Laravel Mailer\n".
            "MAIL_MAILER=failover\n".
            "MAIL_FAILOVER_MAILERS=brevo,resend,smtp2go\n".
            "MAILER_BREVO_API_KEY=\n".
            "MAILER_RESEND_API_KEY=\n".
            "MAILER_SMTP2GO_API_KEY=\n";

        foreach ($envFiles as $envFile) {
            if (! $this->files->exists($envFile)) {
                continue;
            }

            $content = $this->files->get($envFile);

            if (str_contains($content, 'MAIL_FAILOVER_MAILERS') || str_contains($content, 'MAILER_BREVO_API_KEY')) {
                $this->components->twoColumnDetail(basename($envFile), '<fg=yellow;options=bold>ALREADY CONFIGURED</>');

                continue;
            }

            $this->files->append($envFile, $envSnippet);
            $this->components->twoColumnDetail(basename($envFile), '<fg=green;options=bold>UPDATED</>');
        }
    }
}
