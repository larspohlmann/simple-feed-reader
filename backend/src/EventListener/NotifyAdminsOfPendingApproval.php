<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Dto\Mail\PendingApprovalNotice;
use App\Enum\UserStatus;
use App\Event\UserAwaitingApproval;
use App\Repository\UserRepository;
use App\Service\Mail\AccountMailerInterface;
use App\Service\Settings\PublicBaseUrl;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Tells the admins who can act on it that a new account is waiting. One mail per
 * active admin, each in that admin's own language; the send is deferred, so this
 * adds nothing to the latency of the request that triggered the approval.
 */
#[AsEventListener(event: UserAwaitingApproval::class, method: '__invoke')]
final readonly class NotifyAdminsOfPendingApproval
{
    public function __construct(
        private UserRepository $users,
        private AccountMailerInterface $mailer,
        private LoggerInterface $logger,
        private PublicBaseUrl $publicBaseUrl,
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function __invoke(UserAwaitingApproval $event): void
    {
        $admins = $this->users->findActiveAdmins();
        if ([] === $admins) {
            // Not an error: a single-admin instance whose one admin is the
            // person being approved, or a fresh install before the first admin
            // exists, both land here. Nothing to send, and nothing is wrong.
            $this->logger->debug('User entered the approval queue but there are no active admins to notify.');

            return;
        }

        $notice = new PendingApprovalNotice(
            $event->user->getEmail(),
            $event->method,
            $event->oauthProvider,
            $this->publicBaseUrl->get() . '/admin/users',
            $this->users->countByStatus(UserStatus::PendingApproval),
        );

        foreach ($admins as $admin) {
            $this->mailer->sendPendingApprovalNotice($admin, $notice);
        }
    }
}
