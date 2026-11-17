<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\Model;

use App\Entity\NotificationContext;

final readonly class NotificationPageModel
{
    /**
     * @param list<FetchedNotificationModel> $notifications
     * @param \DateTimeImmutable|null $seenUntil every stored row created at or before it is seen
     */
    public function __construct(
        public array $notifications,
        public ?\DateTimeImmutable $seenUntil,
    ) {
    }

    /** @return list<FetchedNotificationModel> */
    public function oldestFirst(): array
    {
        $sorted = $this->notifications;
        usort(
            $sorted,
            static fn (FetchedNotificationModel $left, FetchedNotificationModel $right): int => [
                $left->createdAt,
                $left->remoteId,
            ] <=> [$right->createdAt, $right->remoteId],
        );

        return $sorted;
    }

    /** @param array<array-key, NotificationContext> $contexts by remote id */
    #[\NoDiscard]
    public function withContexts(array $contexts): self
    {
        return clone($this, ['notifications' => array_map(
            static fn (FetchedNotificationModel $fetched): FetchedNotificationModel
                => isset($contexts[$fetched->remoteId])
                    ? $fetched->withContext($contexts[$fetched->remoteId])
                    : $fetched,
            $this->notifications,
        )]);
    }
}
