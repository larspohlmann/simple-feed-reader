<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\Preferences;
use App\Entity\User;
use App\Enum\MailKind;
use App\Service\Mail\Digest\DigestMailer\DigestMailerInterface;
use App\Service\Mail\Digest\DigestRecipients\DigestRecipientsInterface;
use App\Service\Mail\Digest\Model\DigestAttempt;
use App\Service\Mail\Digest\Model\DigestModel;
use App\Service\Mail\Digest\Model\DigestSweepReportModel;
use App\Service\Mail\MailCapability;
use App\Service\Mail\MailFailureRecorder\MailFailureRecorderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * The sweep both the worker (a Docker tick loop) and the maintenance command
 * (Strato's external cron) call: find every account whose scheduled digest
 * occurrence has passed since it last sent, compose it, and mail it.
 *
 * This is the security boundary for the digest, not the settings UI: mail
 * capability and email verification are re-checked here even though the UI
 * already gates them, because a preferences row can outlive the state that
 * made it valid (mail disabled after the fact, verification revoked) (#636).
 */
final readonly class SendDueDigests
{
    public function __construct(
        private DigestRecipientsInterface $recipients,
        private DigestSchedule $schedule,
        private DigestComposer $composer,
        private DigestMailerInterface $mailer,
        private MailCapability $mail,
        private ClockInterface $clock,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private MailFailureRecorderInterface $health,
    ) {
    }

    public function run(): DigestSweepReportModel
    {
        if (!$this->mail->isEnabled()) {
            return new DigestSweepReportModel(0, 0, 0);
        }

        $now = $this->clock->now();
        $considered = 0;
        $sent = 0;
        $skippedEmpty = 0;

        foreach ($this->recipients->findWithDigestEnabled() as $preferences) {
            ++$considered;

            $attempt = $this->attemptSend($preferences, $now);
            if (DigestAttempt::Sent === $attempt) {
                ++$sent;
            } elseif (DigestAttempt::NothingToReport === $attempt) {
                ++$skippedEmpty;
            }
        }

        return new DigestSweepReportModel($considered, $sent, $skippedEmpty);
    }

    private function attemptSend(Preferences $preferences, \DateTimeImmutable $now): DigestAttempt
    {
        $occurrence = $this->dueOccurrence($preferences, $now);
        if (null === $occurrence) {
            return DigestAttempt::NotDue;
        }

        $user = $preferences->getUser();
        if (!$user->isEmailVerified()) {
            return DigestAttempt::Ineligible;
        }

        $model = $this->composer->compose($user, $preferences->getDigestLastSentAt() ?? $occurrence);
        if (null === $model) {
            return DigestAttempt::NothingToReport;
        }

        return $this->sendAndAdvance($user, $model, $preferences, $occurrence);
    }

    /** One recipient's transport failure must not stop the sweep; the untouched watermark retries it next tick (#636). */
    private function sendAndAdvance(
        User $user,
        DigestModel $model,
        Preferences $preferences,
        \DateTimeImmutable $occurrence,
    ): DigestAttempt {
        try {
            $this->mailer->send($user, $model);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error(
                'Digest send failed: {userId} <{email}>',
                ['userId' => $user->getId(), 'email' => $user->getEmail(), 'exception' => $exception],
            );
            $this->health->recordFailure(MailKind::Digest, $user->getEmail(), $exception->getMessage());

            return DigestAttempt::SendFailed;
        }

        $this->health->recordSuccess();
        $preferences->setDigestLastSentAt($occurrence);
        $this->entityManager->flush();

        return DigestAttempt::Sent;
    }

    /** The schedule's occurrence, but only if it is newer than the last send. */
    private function dueOccurrence(Preferences $preferences, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $occurrence = $this->schedule->mostRecentDue($preferences, $now);
        if (null === $occurrence) {
            return null;
        }

        $lastSent = $preferences->getDigestLastSentAt();

        return (null === $lastSent || $lastSent < $occurrence) ? $occurrence : null;
    }
}
