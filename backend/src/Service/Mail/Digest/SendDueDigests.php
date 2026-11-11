<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\DigestSettings;
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
 * Mails every account whose digest occurrence has passed since its last send, for the worker and the maintenance cron.
 * The digest's security boundary: mail capability, email verification and account status are re-checked here, because
 * a settings row can outlive the state that made it valid.
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
            return DigestSweepReportModel::none();
        }

        $now = $this->clock->now();
        $considered = 0;
        $sent = 0;
        $skippedEmpty = 0;

        foreach ($this->recipients->findWithDigestEnabled() as $settings) {
            ++$considered;

            $attempt = $this->attemptSend($settings, $now);
            if (DigestAttempt::Sent === $attempt) {
                ++$sent;
            } elseif (DigestAttempt::NothingToReport === $attempt) {
                ++$skippedEmpty;
            }
        }

        return new DigestSweepReportModel($considered, $sent, $skippedEmpty);
    }

    private function attemptSend(DigestSettings $settings, \DateTimeImmutable $now): DigestAttempt
    {
        $occurrence = $this->dueOccurrence($settings, $now);
        if (null === $occurrence) {
            return DigestAttempt::NotDue;
        }

        $user = $settings->getUser();
        if (!$user->isEmailVerified() || !$user->isActiveAt($now)) {
            return DigestAttempt::Ineligible;
        }

        $model = $this->composer->compose($user, $settings->getLastSentAt() ?? $occurrence);
        if (null === $model) {
            return DigestAttempt::NothingToReport;
        }

        return $this->sendAndAdvance($settings, $model, $occurrence);
    }

    /** One recipient's transport failure must not stop the sweep; the untouched watermark retries it next tick. */
    private function sendAndAdvance(
        DigestSettings $settings,
        DigestModel $model,
        \DateTimeImmutable $occurrence,
    ): DigestAttempt {
        try {
            $this->mailer->send($settings, $model);
        } catch (TransportExceptionInterface $exception) {
            $user = $settings->getUser();
            $this->logger->error(
                'Digest send failed: {userId} <{email}>',
                ['userId' => $user->getId(), 'email' => $user->getEmail(), 'exception' => $exception],
            );
            $this->health->recordFailure(MailKind::Digest, $user->getEmail(), $exception->getMessage());

            return DigestAttempt::SendFailed;
        }

        $this->health->recordSuccess();
        $settings->setLastSentAt($occurrence);
        $this->entityManager->flush();

        return DigestAttempt::Sent;
    }

    /** The schedule's occurrence, but only if it is newer than the last send. */
    private function dueOccurrence(DigestSettings $settings, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $occurrence = $this->schedule->mostRecentDue($settings, $now);
        if (null === $occurrence) {
            return null;
        }

        $lastSent = $settings->getLastSentAt();

        return (null === $lastSent || $lastSent < $occurrence) ? $occurrence : null;
    }
}
