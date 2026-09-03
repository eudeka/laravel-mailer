<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Console\Commands;

use Eudeka\LaravelMailer\DTO\Address;
use Eudeka\LaravelMailer\DTO\NormalizedEmailPayload;
use Eudeka\LaravelMailer\Exceptions\AllProvidersFailedException;
use Eudeka\LaravelMailer\Exceptions\NoActiveProvidersException;
use Eudeka\LaravelMailer\LaravelMailer;
use Illuminate\Console\Command;
use Throwable;

final class TestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mailer:test 
                            {recipient : The recipient email address} 
                            {--from= : Sender email address} 
                            {--from-name= : Sender name} 
                            {--subject=Multi-Vendor Email Delivery Test : Email subject} 
                            {--body=This is a test email sent from the Laravel multi-vendor mailer package. : Email body content}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch a test email through the multi-vendor failover pipeline';

    /**
     * Execute the console command.
     */
    public function handle(LaravelMailer $mailer): int
    {
        $rawRecipient = $this->argument('recipient');
        $recipient = is_string($rawRecipient) ? $rawRecipient : '';

        $fromAddress = $this->option('from');
        $fromName = $this->option('from-name');

        $rawSubject = $this->option('subject');
        $subject = is_string($rawSubject) ? $rawSubject : 'Multi-Vendor Email Delivery Test';

        $rawBody = $this->option('body');
        $body = is_string($rawBody) ? $rawBody : '';

        /** @var string $defaultFrom */
        $defaultFrom = is_string($fromAddress) && $fromAddress !== ''
            ? $fromAddress
            : (string) config('mail.from.address', 'noreply@example.com');

        /** @var string $defaultName */
        $defaultName = is_string($fromName) && $fromName !== ''
            ? $fromName
            : (string) config('mail.from.name', 'Laravel Mailer');

        $payload = new NormalizedEmailPayload(
            from: new Address(address: $defaultFrom, name: $defaultName),
            to: [new Address(address: $recipient)],
            subject: $subject,
            html: sprintf('<p>%s</p>', htmlspecialchars($body, ENT_QUOTES, 'UTF-8')),
            text: $body,
        );

        $this->info(sprintf('Dispatching test email to [%s] via multi-vendor pipeline...', $recipient));

        try {
            $response = $mailer->send($payload);

            $this->components->info(sprintf(
                'Email delivered successfully via provider [%s]! Status code: %d. Message ID: %s',
                $response->providerName,
                $response->statusCode,
                $response->messageId ?? 'N/A',
            ));

            return self::SUCCESS;
        } catch (NoActiveProvidersException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (AllProvidersFailedException $e) {
            $this->components->error('All configured providers failed to deliver the email:');

            $rows = [];
            foreach ($e->failures as $provider => $failure) {
                $rows[] = [
                    $provider,
                    $failure->statusCode,
                    $failure->errorMessage ?? 'Unknown error',
                ];
            }

            $this->table(['Provider', 'Status Code', 'Error Reason'], $rows);

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error(sprintf('Unexpected error occurred: %s', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
