<?php

declare(strict_types=1);

namespace App\Service\Social\Notification;

use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Repository\SocialNotificationRepository;
use App\Service\Social\Notification\NotificationSource\NotificationSourceInterface;
use App\Service\Social\Social\ActingAccountGuard;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class NotificationSeenMarker
{
    public function __construct(
        #[AutowireLocator('app.social_notification_source')]
        private ContainerInterface $sources,
        private ActingAccountGuard $guard,
        private SocialAccountRepository $accounts,
        private SocialNotificationRepository $notifications,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function markSeen(User $user, SocialNetwork $network, int $upTo): void
    {
        $source = $this->sources->get($network->value);
        \assert($source instanceof NotificationSourceInterface);

        foreach ($this->accounts->findConnectedOnNetwork($user, $network) as $account) {
            $this->markAccountSeen($source, $account, $upTo);
        }
    }

    private function markAccountSeen(NotificationSourceInterface $source, SocialAccount $account, int $upTo): void
    {
        $unseen = $this->notifications->findUnseenUpTo($account, $upTo);
        if ($unseen === []) {
            return;
        }

        try {
            $this->guard->actAs($account, static function () use ($source, $account, $unseen): void {
                $source->markSeen($account, $unseen, static function (SocialNotification $row): void {
                    $row->markSeen();
                });
            });
        } finally {
            $this->entityManager->flush();
        }
    }
}
