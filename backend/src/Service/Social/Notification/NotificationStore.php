<?php

declare(strict_types=1);

namespace App\Service\Social\Notification;

use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Repository\SocialNotificationRepository;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\Model\NotificationPageModel;
use Doctrine\ORM\EntityManagerInterface;

final readonly class NotificationStore
{
    public const string RETENTION = '-30 days';

    public function __construct(
        private SocialNotificationRepository $notifications,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
    ) {
    }

    /** @return int how many rows were new */
    public function record(SocialAccount $account, NotificationPageModel $page): int
    {
        $retained = $this->retained($page->oldestFirst());
        $stored = $this->notifications->findByRemoteIds(
            $account,
            array_map(static fn (FetchedNotificationModel $fetched): string => $fetched->remoteId, $retained),
        );

        $inserted = 0;
        foreach ($retained as $fetched) {
            $row = $stored[$fetched->remoteId] ?? null;
            if ($row === null) {
                $this->entityManager->persist($this->rowOf($account, $fetched, $page->seenUntil));
                ++$inserted;
                continue;
            }
            if ($fetched->seen) {
                $row->markSeen();
            }
        }
        $this->entityManager->flush();

        if ($page->seenUntil !== null) {
            $this->notifications->markSeenUpTo($account, $page->seenUntil);
        }

        return $inserted;
    }

    public function prune(): int
    {
        return $this->notifications->deleteCreatedBefore($this->retentionCutoff());
    }

    /**
     * @param list<FetchedNotificationModel> $notifications
     * @return list<FetchedNotificationModel>
     */
    private function retained(array $notifications): array
    {
        $cutoff = $this->retentionCutoff();

        return array_values(array_filter(
            $notifications,
            static fn (FetchedNotificationModel $fetched): bool => $fetched->createdAt >= $cutoff,
        ));
    }

    private function rowOf(
        SocialAccount $account,
        FetchedNotificationModel $fetched,
        ?\DateTimeImmutable $seenUntil,
    ): SocialNotification {
        return new SocialNotification(
            $account,
            $fetched->remoteId,
            $fetched->kind,
            $fetched->actor,
            $fetched->subject,
            $fetched->createdAt,
            $fetched->seen || ($seenUntil !== null && $fetched->createdAt <= $seenUntil),
        );
    }

    private function retentionCutoff(): \DateTimeImmutable
    {
        return $this->clock->now()->modify(self::RETENTION);
    }
}
