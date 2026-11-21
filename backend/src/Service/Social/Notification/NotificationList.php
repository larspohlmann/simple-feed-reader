<?php

declare(strict_types=1);

namespace App\Service\Social\Notification;

use App\Entity\NotificationActor;
use App\Entity\NotificationSubject;
use App\Entity\SocialNotification;
use App\Entity\User;
use App\Enum\CanonicalItemKind;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialNetwork;
use App\Enum\SocialNotificationKind;
use App\Repository\CanonicalItemRepository;
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
        private CanonicalItemRepository $canonicalItems,
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
        $newest = array_filter(
            $this->notifications->findNewestPerGroup($shown, self::ACTORS_PER_GROUP),
            static fn (array $rows): bool => $rows !== [],
        );
        $subjects = array_map(static fn (array $rows): NotificationSubject => $rows[0]->getSubject(), $newest);
        $hashes = array_merge(...array_map(
            static fn (NotificationSubject $subject): array => $subject->keys->hashes(),
            $subjects,
        ));
        $entryIds = $this->canonicalItems->findReadableEntryIds(
            $user->requireId(),
            CanonicalItemKind::fromNetwork($network),
            $hashes,
        );

        $groups = [];
        foreach ($shown as $group) {
            $rows = $newest[$group['latestId']] ?? null;
            if ($rows === null) {
                continue;
            }
            $groups[] = new NotificationGroupModel(
                $group['latestId'],
                SocialNotificationKind::from($group['kind']),
                $handles[$group['accountId']],
                array_map(static fn (SocialNotification $row): NotificationActor => $row->getActor(), $rows),
                $group['count'],
                $subjects[$group['latestId']],
                $rows[0]->getCreatedAt(),
                $group['seen'],
                $subjects[$group['latestId']]->keys->pickEntryId($entryIds),
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
