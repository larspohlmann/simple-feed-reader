<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\MailKind;
use App\Entity\MailSendFailure;
use App\Http\MailDeliveryHealthJson;
use App\Repository\MailSendFailureRepository;
use App\Service\Clock\NaiveUtcClock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The in-app signal that automated mail is failing (#882). Every send path
 * records its outcome here; any success clears the whole log, so a non-empty
 * log means the most recent send failed.
 */
final readonly class MailDeliveryHealth implements MailFailureRecorder
{
    public function __construct(
        private MailSendFailureRepository $failures,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
    ) {
    }

    public function recordFailure(MailKind $kind, string $recipient, string $error): void
    {
        $this->failures->add(new MailSendFailure($kind, $recipient, $error, $this->clock->now()));
        $this->entityManager->flush();
        $this->failures->pruneToRetention();
    }

    public function recordSuccess(): void
    {
        $this->failures->deleteAll();
    }

    /**
     * @return array{failures: list<array{kind: string, recipient: string, error: string, at: string}>}
     */
    public function view(): array
    {
        return MailDeliveryHealthJson::view($this->failures->recent(MailSendFailureRepository::RETENTION));
    }
}
