<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Holds outgoing mail until the response has been flushed to the client, then
 * hands it to the real mailer.
 *
 * Two reasons, in order of importance. (1) Timing: a synchronous SMTP round
 * trip to Strato sits inside the measured request, so "we sent you a mail"
 * takes hundreds of ms longer than "we did nothing" — on /register and
 * /password-reset-request that difference IS the account-enumeration oracle
 * the identical response bodies were meant to close. Moving the send past the
 * response takes it out of anything the caller can measure. (2) Latency: the
 * user gets their 202 without waiting on someone else's SMTP server.
 *
 * Why not Messenger: an async transport needs a worker draining the queue,
 * and the production host has no daemons or crontab. A queue nothing drains
 * fails silently, which is worse than a slow send. kernel.terminate always
 * runs in the same process that handled the request, so mail is either sent
 * or logged as failed — never quietly parked.
 *
 * Not readonly: the queue is the point.
 */
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
     * Drains the queue. Emptied *before* sending, so a transport that throws
     * cannot leave the same message queued for a later flush to retry blindly.
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
