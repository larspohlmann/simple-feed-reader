<?php

declare(strict_types=1);

namespace App\Service\Social\Notification;

use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Entity\User;
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
            return new NotificationListPageModel([], null, []);
        }

        $accountIds = array_map(static fn (SocialAccount $account): int => $account->requireId(), $accounts);
        $handles = array_combine($accountIds, array_map(
            static fn (SocialAccount $account): string => $account->getHandle(),
            $accounts,
        ));
        $found = $this->notifications->pageOfGroups($accountIds, $cursor, self::PAGE_SIZE);
        $hasNextPage = count($found) > self::PAGE_SIZE;
        $shown = array_slice($found, 0, self::PAGE_SIZE);
        $newest = $this->notifications->newestOfGroups($shown, self::ACTORS_PER_GROUP);

        $groups = [];
        foreach ($shown as $group) {
            $rows = $newest[$group['latestId']];
            if ($rows === []) {
                continue;
            }
            $groups[] = $this->groupOf(
                $group['latestId'],
                SocialNotificationKind::from($group['kind']),
                $handles[$group['accountId']],
                $rows,
                $group['count'],
                $group['seen'],
            );
        }

        return new NotificationListPageModel(
            $groups,
            $hasNextPage ? $shown[array_key_last($shown)]['latestId'] : null,
            $this->polls->scopeMissingHandles($accountIds),
        );
    }

    /** @param non-empty-list<SocialNotification> $newest */
    private function groupOf(
        int $latestId,
        SocialNotificationKind $kind,
        string $accountHandle,
        array $newest,
        int $actorCount,
        bool $seen,
    ): NotificationGroupModel {
        return new NotificationGroupModel(
            $latestId,
            $kind,
            $accountHandle,
            array_map(static fn (SocialNotification $row) => $row->getActor(), $newest),
            $actorCount,
            $newest[0]->getSubject(),
            $newest[0]->getCreatedAt(),
            $seen,
        );
    }
}
