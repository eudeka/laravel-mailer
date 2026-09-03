<?php

declare(strict_types=1);

namespace Eudeka\LaravelMailer\Transport;

use Eudeka\LaravelMailer\Normalizer\PayloadNormalizer;
use Eudeka\LaravelMailer\Pipeline\FailoverPipeline;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

final class MultiVendorTransport extends AbstractTransport
{
    public function __construct(
        private readonly FailoverPipeline $pipeline,
        private readonly PayloadNormalizer $normalizer,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger);
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        $email = match (true) {
            $original instanceof Email => $original,
            $original instanceof Message => MessageConverter::toEmail($original),
            default => new Email,
        };

        $payload = $this->normalizer->normalize($email);

        $response = $this->pipeline->send($payload);

        if ($response->messageId !== null) {
            $message->setMessageId($response->messageId);
        }

        $message->appendDebug(sprintf('Sent via provider: %s', $response->providerName));
    }

    public function __toString(): string
    {
        return 'multi-vendor';
    }
}
