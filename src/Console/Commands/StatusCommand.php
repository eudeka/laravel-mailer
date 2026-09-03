<?php

declare(strict_types=1);

namespace EmailProvider\EmailProvider\Console\Commands;

use EmailProvider\EmailProvider\EmailProvider;
use Illuminate\Console\Command;

final class StatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email-provider:status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display the priority order, credentials status, and circuit breaker health of email providers';

    /**
     * Execute the console command.
     */
    public function handle(EmailProvider $emailProvider): int
    {
        $statuses = $emailProvider->status();

        if ($statuses === []) {
            $this->warn('No email providers configured in priority list.');

            return self::SUCCESS;
        }

        $rows = [];
        $priority = 1;

        foreach ($statuses as $status) {
            $credentials = $status['configured']
                ? '<fg=green>Configured</>'
                : '<fg=red>Missing (Pruned)</>';

            $breaker = $status['healthy']
                ? '<fg=green>Healthy</>'
                : sprintf('<fg=yellow>Cooldown (%ds remaining)</>', $status['cooldown']);

            $state = match (true) {
                ! $status['configured'] => '<fg=gray>Pruned</>',
                ! $status['healthy'] => '<fg=yellow>Degraded (In Cooldown)</>',
                default => '<fg=green;options=bold>Active</>',
            };

            $rows[] = [
                $priority++,
                $status['name'],
                $credentials,
                $breaker,
                $state,
            ];
        }

        $this->table(
            ['Priority', 'Provider', 'Credentials', 'Circuit Breaker', 'Effective State'],
            $rows,
        );

        return self::SUCCESS;
    }
}
