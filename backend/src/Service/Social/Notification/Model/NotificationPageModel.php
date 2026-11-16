<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\Model;

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
}
