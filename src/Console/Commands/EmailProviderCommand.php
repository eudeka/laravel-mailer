<?php

declare(strict_types=1);

namespace EmailProvider\EmailProvider\Console\Commands;

use Illuminate\Console\Command;

class EmailProviderCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'email-provider:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package email-provider.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('EmailProvider placeholder command executed.');

        return self::SUCCESS;
    }
}
