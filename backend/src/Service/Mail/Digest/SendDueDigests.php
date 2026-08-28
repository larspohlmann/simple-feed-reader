<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\Preferences;
use App\Entity\User;
use App\Repository\PreferencesRepository;
use App\Service\Mail\MailCapability;
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

            $outcome = $this->attemptSend($prefs, $now);
            if (true === $outcome) {
                ++$sent;
            } elseif (false === $outcome) {
                ++$skippedEmpty;
            }
        }

        return new DigestSweepReport($considered, $sent, $skippedEmpty);
    }

    /**
     * Null: not due; due but not eligible to receive mail right now; or the
     * send to this recipient failed and was isolated so it does not stop the
     * sweep (retried next tick, since the watermark was left untouched).
     * True: composed something and sent it.
     * False: due and eligible, but there was nothing to report.
     */
    private function attemptSend(Preferences $prefs, \DateTimeImmutable $now): ?bool
    {
        $occurrence = $this->dueOccurrence($prefs, $now);
        if (null === $occurrence) {
            return null;
        }

        $user = $prefs->getUser();
        if (!$user->isEmailVerified()) {
            return null;
        }

        $since = $prefs->getDigestLastSentAt() ?? $occurrence;
        $model = $this->composer->compose($user, $since);
        if (null === $model) {
            return false;
        }

        return $this->sendAndAdvance($user, $model, $prefs, $occurrence);
    }

    /**
     * A recipient's mail transport can reject or fail independently of every
     * other recipient (relay error, rejected mailbox). One bad address must
     * not starve the rest of the sweep, so the send is isolated here: on
     * failure the watermark is left untouched, and the occurrence is retried
     * on the next tick (#636).
     */
    private function sendAndAdvance(
        User $user,
        DigestModel $model,
        Preferences $prefs,
        \DateTimeImmutable $occurrence,
    ): ?bool {
        try {
            $this->mailer->send($user, $model);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error(
                'Digest send failed: {userId} <{email}>',
                ['userId' => $user->getId(), 'email' => $user->getEmail(), 'exception' => $e],
            );

            return null;
        }

        $prefs->setDigestLastSentAt($occurrence);
        $this->em->flush();

        return true;
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
