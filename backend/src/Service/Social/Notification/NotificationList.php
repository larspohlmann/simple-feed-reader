<?php

declare(strict_types=1);

namespace App\Service\Social\Notification;

use App\Entity\NotificationActor;
use App\Entity\SocialNotification;
use App\Entity\User;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialNetwork;
use App\Enum\SocialNotificationKind;
use App\Repository\SocialAccountRepository;
use App\Repository\SocialNotificationPollRepository;
use App\Repository\SocialNotificationRepository;
use App\Service\Social\Notification\Model\NotificationGroupModel;
use App\Service\Social\Notification\Model\NotificationListPageModel;

final readonly class NotificationList
{
    public const int PAGE_SIZE = 30;
    public const int ACTORS_PER_GROUP = 3;

    public function __construct(
        private SocialAccountRepository $accounts,
        private SocialNotificationRepository $notifications,
        private SocialNotificationPollRepository $polls,
    ) {
    }

    public function page(User $user, SocialNetwork $network, ?int $cursor): NotificationListPageModel
    {
        $accounts = $this->accounts->findAllOnNetwork($user, $network);
        if ($accounts === []) {
            return new NotificationListPageModel([], null, [], []);
        }

        $handles = [];
        $needingReconnect = [];
        foreach ($accounts as $account) {
            $handles[$account->requireId()] = $account->getHandle();
            if ($account->getStatus() === SocialAccountStatus::NeedsReconnect) {
                $needingReconnect[] = $account->getHandle();
            }
        }
        $accountIds = array_keys($handles);
        $found = $this->notifications->findGroupPage($accountIds, $cursor, self::PAGE_SIZE);
        $hasNextPage = count($found) > self::PAGE_SIZE;
        $shown = array_slice($found, 0, self::PAGE_SIZE);
        $newest = $this->notifications->findNewestPerGroup($shown, self::ACTORS_PER_GROUP);

        $groups = [];
        foreach ($shown as $group) {
            $rows = $newest[$group['latestId']];
            if ($rows === []) {
                continue;
            }
            $groups[] = new NotificationGroupModel(
                $group['latestId'],
                SocialNotificationKind::from($group['kind']),
                $handles[$group['accountId']],
                array_map(static fn (SocialNotification $row): NotificationActor => $row->getActor(), $rows),
                $group['count'],
                $rows[0]->getSubject(),
                $rows[0]->getCreatedAt(),
                $group['seen'],
            );
        }

        return new NotificationListPageModel(
            $groups,
            $hasNextPage ? $shown[array_key_last($shown)]['latestId'] : null,
            array_map(
                static fn (int $accountId): string => $handles[$accountId],
                $this->polls->scopeMissingAccountIds($accountIds),
            ),
            $needingReconnect,
        );
    }
}
