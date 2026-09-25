<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\MailKind;
use App\Entity\Preferences;
use App\Entity\User;
use App\Repository\PreferencesRepository;
use App\Service\Mail\MailCapability;
use App\Service\Mail\MailFailureRecorder;
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
        private PreferencesRepository $preferences,
        private DigestSchedule $schedule,
        private DigestComposer $composer,
        private DigestMailerInterface $mailer,
        private MailCapability $mail,
        private ClockInterface $clock,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private MailFailureRecorder $health,
    ) {
    }

    public function run(): DigestSweepReport
    {
        if (!$this->mail->isEnabled()) {
            return new DigestSweepReport(0, 0, 0);
        }

        $now = $this->clock->now();
        $considered = 0;
        $sent = 0;
        $skippedEmpty = 0;

        foreach ($this->preferences->findWithDigestEnabled() as $prefs) {
            ++$considered;

            $attempt = $this->attemptSend($prefs, $now);
            if (DigestAttempt::Sent === $attempt) {
                ++$sent;
            } elseif (DigestAttempt::NothingToReport === $attempt) {
                ++$skippedEmpty;
            }
        }

        return new DigestSweepReport($considered, $sent, $skippedEmpty);
    }

    private function attemptSend(Preferences $prefs, \DateTimeImmutable $now): DigestAttempt
    {
        $occurrence = $this->dueOccurrence($prefs, $now);
        if (null === $occurrence) {
            return DigestAttempt::NotDue;
        }

        $user = $prefs->getUser();
        if (!$user->isEmailVerified()) {
            return DigestAttempt::Ineligible;
        }

        $model = $this->composer->compose($user, $prefs->getDigestLastSentAt() ?? $occurrence);
        if (null === $model) {
            return DigestAttempt::NothingToReport;
        }

        return $this->sendAndAdvance($user, $model, $prefs, $occurrence);
    }

    /** One recipient's transport failure must not stop the sweep; the untouched watermark retries it next tick (#636). */
    private function sendAndAdvance(
        User $user,
        DigestModel $model,
        Preferences $prefs,
        \DateTimeImmutable $occurrence,
    ): DigestAttempt {
        try {
            $this->mailer->send($user, $model);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error(
                'Digest send failed: {userId} <{email}>',
                ['userId' => $user->getId(), 'email' => $user->getEmail(), 'exception' => $e],
            );
            $this->health->recordFailure(MailKind::Digest, $user->getEmail(), $e->getMessage());

            return DigestAttempt::SendFailed;
        }

        $this->health->recordSuccess();
        $prefs->setDigestLastSentAt($occurrence);
        $this->em->flush();

        return DigestAttempt::Sent;
    }

    /** The schedule's occurrence, but only if it is newer than the last send. */
    private function dueOccurrence(Preferences $prefs, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $occurrence = $this->schedule->mostRecentDue($prefs, $now);
        if (null === $occurrence) {
            return null;
        }

        $lastSent = $prefs->getDigestLastSentAt();

        return (null === $lastSent || $lastSent < $occurrence) ? $occurrence : null;
    }
}
