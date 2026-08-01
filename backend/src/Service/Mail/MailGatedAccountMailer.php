<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Dto\Mail\PendingApprovalNotice;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * On a mailless instance (issue #230) every account mail is a no-op that leaves
 * a log line, instead of a send that silently succeeds into null://null. The
 * log line is what makes "no approval mail went out" visible to the operator
 * rather than a mystery. Decorates AccountMailer so no send site has to know
 * whether mail is on.
 */
#[AsDecorator(decorates: AccountMailer::class)]
final readonly class MailGatedAccountMailer implements AccountMailerInterface
{
    public function __construct(
        private AccountMailerInterface $inner,
        private MailCapability $mail,
        private LoggerInterface $logger,
    ) {
    }

    public function sendVerification(User $user, string $plainToken): void
    {
        $this->send('verification', $user, fn () => $this->inner->sendVerification($user, $plainToken));
    }

    public function sendApproved(User $user): void
    {
        $this->send('approved', $user, fn () => $this->inner->sendApproved($user));
    }

    public function sendPasswordReset(User $user, string $plainToken): void
    {
        $this->send('password reset', $user, fn () => $this->inner->sendPasswordReset($user, $plainToken));
    }

    public function sendPendingApprovalNotice(User $admin, PendingApprovalNotice $notice): void
    {
        $this->send(
            'pending-approval notice',
            $admin,
            fn () => $this->inner->sendPendingApprovalNotice($admin, $notice),
        );
    }

    private function send(string $kind, User $recipient, callable $deliver): void
    {
        if (!$this->mail->isEnabled()) {
            $this->logger->info('Mail disabled (MAIL_DISABLED); skipped {kind} mail to {email}.', [
                'kind' => $kind,
                'email' => $recipient->getEmail(),
            ]);

            return;
        }

        $deliver();
    }
}
