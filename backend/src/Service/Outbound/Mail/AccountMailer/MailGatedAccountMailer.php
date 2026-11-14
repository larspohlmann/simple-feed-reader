<?php

declare(strict_types=1);

namespace App\Service\Mail\AccountMailer;

use App\Entity\User;
use App\Service\Mail\MailCapability;
use App\Service\Mail\Model\PendingApprovalNoticeModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * On a mailless instance every account mail is a logged no-op, not a silent send into null://null: the log line is
 * how an operator sees that no approval mail went out.
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

    public function sendPendingApprovalNotice(User $admin, PendingApprovalNoticeModel $notice): void
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
            $this->logger->info('Mail disabled; skipped {kind} mail to {email}.', [
                'kind' => $kind,
                'email' => $recipient->getEmail(),
            ]);

            return;
        }

        $deliver();
    }
}
