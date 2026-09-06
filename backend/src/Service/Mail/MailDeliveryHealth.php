<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\MailKind;
use App\Entity\MailSendFailure;
use App\Http\MailDeliveryHealthJson;
use App\Repository\MailSendFailureRepository;
use Psr\Clock\ClockInterface;

/**
 * The in-app signal that automated mail is failing (#882). Every send path
 * records its outcome here; any success clears the whole log, so a non-empty
 * log means the most recent send failed.
 */
final readonly class MailDeliveryHealth
{
    public function __construct(
        private MailSendFailureRepository $failures,
        private ClockInterface $clock,
    ) {
    }

    public function recordFailure(MailKind $kind, string $recipient, string $error): void
    {
        // Naive UTC: the Strato workers run Europe/Berlin, so normalise before persisting.
        $occurredAt = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));

        $this->failures->add(new MailSendFailure($kind, $recipient, $error, $occurredAt));
    }

    public function recordSuccess(): void
    {
        $this->failures->deleteAll();
    }

    /**
     * @return array{count: int, failures: list<array{kind: string, recipient: string, error: string, at: string}>}
     */
    public function view(): array
    {
        return MailDeliveryHealthJson::view(
            $this->failures->recent(MailSendFailureRepository::RETENTION),
            $this->failures->countAll(),
        );
    }
}
