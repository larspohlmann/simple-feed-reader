<?php

declare(strict_types=1);

namespace App\Service\Social\Notification;

use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;

final readonly class NotificationRefresh
{
    public function __construct(
        private SocialAccountRepository $accounts,
        private NotificationPoll $notificationPoll,
    ) {
    }

    public function refresh(User $user, SocialNetwork $network): void
    {
        foreach ($this->accounts->findConnectedOnNetwork($user, $network) as $account) {
            $this->notificationPoll->poll($account);
        }
    }
}
