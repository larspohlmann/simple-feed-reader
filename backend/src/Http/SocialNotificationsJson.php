<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\NotificationActor;
use App\Service\Social\Notification\Model\NotificationGroupModel;
use App\Service\Social\Notification\Model\NotificationListPageModel;

/**
 * @phpstan-type ActorJson array{
 *     handle: string, displayName: string|null, avatarUrl: string|null, profileUrl: string
 * }
 * @phpstan-type GroupJson array{
 *     id: int, kind: string, account: string, actors: list<ActorJson>,
 *     actorCount: int, excerpt: string|null, linkUrl: string, latestAt: string, seen: bool
 * }
 */
final class SocialNotificationsJson
{
    /**
     * @return array{
     *     groups: list<GroupJson>, nextCursor: int|null, scopeMissing: list<string>, needsReconnect: list<string>
     * }
     */
    public static function page(NotificationListPageModel $page): array
    {
        return [
            'groups' => array_map(self::group(...), $page->groups),
            'nextCursor' => $page->nextCursor,
            'scopeMissing' => $page->scopeMissingHandles,
            'needsReconnect' => $page->needsReconnectHandles,
        ];
    }

    /** @return GroupJson */
    private static function group(NotificationGroupModel $group): array
    {
        return [
            'id' => $group->latestId,
            'kind' => $group->kind->value,
            'account' => $group->accountHandle,
            'actors' => array_map(self::actor(...), $group->actors),
            'actorCount' => $group->actorCount,
            'excerpt' => $group->subject->excerpt,
            'linkUrl' => $group->subject->linkUrl,
            'latestAt' => $group->latestAt->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
            'seen' => $group->seen,
        ];
    }

    /** @return ActorJson */
    private static function actor(NotificationActor $actor): array
    {
        return [
            'handle' => $actor->handle,
            'displayName' => $actor->displayName,
            'avatarUrl' => $actor->avatarUrl,
            'profileUrl' => $actor->profileUrl,
        ];
    }
}
