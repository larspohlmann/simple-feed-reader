<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\DependencyInjection\ProcessLifetimeState;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Holds outgoing mail until kernel.terminate, after the response is flushed: an SMTP round trip inside /register or
 * /password-reset-request would leak by timing whether the account exists. Not Messenger: a queue needs a worker.
 */
#[ProcessLifetimeState('Drained on kernel.terminate; a reset between messages would drop queued mail')]
final class DeferredMailer implements MailerInterface
{
    /** @var list<array{RawMessage, ?Envelope}> */
    private array $queued = [];

    public function __construct(
        private readonly MailerInterface $mailer,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->queued[] = [$message, $envelope];
    }

    /**
     * Emptied *before* sending, so a transport that throws cannot leave the same message queued for a later flush to
     * retry blindly.
     *
     * @return list<array{RawMessage, ?Envelope}>
     */
    public function take(): array
    {
        $queued = $this->queued;
        $this->queued = [];

        return $queued;
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function sendNow(RawMessage $message, ?Envelope $envelope): void
    {
        $this->mailer->send($message, $envelope);
    }

    public function hasQueuedMail(): bool
    {
        return [] !== $this->queued;
    }
}
