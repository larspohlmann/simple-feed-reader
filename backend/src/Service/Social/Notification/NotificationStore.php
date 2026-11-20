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

    /** @return int the rows stored that were not there before */
    public function record(SocialAccount $account, NotificationPageModel $page): int
    {
        $retained = $this->retained($page->oldestFirst());
        $stored = $this->loadStored($account, $retained);

        $inserted = 0;
        foreach ($retained as $fetched) {
            $row = $stored[$fetched->remoteId] ?? null;
            if ($row === null) {
                $this->entityManager->persist($this->buildRow($account, $fetched, $page->seenUntil));
                ++$inserted;
                continue;
            }
            if ($fetched->seen || $this->isAtOrBefore($row->getCreatedAt(), $page->seenUntil)) {
                $row->markSeen();
            }
        }
        $this->entityManager->flush();

        if ($page->seenUntil !== null) {
            $this->notifications->markSeenUpTo($account, $page->seenUntil);
        }

        return $inserted;
    }

    /** @return list<FetchedNotificationModel> the rows record() would insert */
    public function unstored(SocialAccount $account, NotificationPageModel $page): array
    {
        $retained = $this->retained($page->oldestFirst());
        $stored = $this->loadStored($account, $retained);

        return array_values(array_filter(
            $retained,
            static fn (FetchedNotificationModel $fetched): bool => !isset($stored[$fetched->remoteId]),
        ));
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

        $byRemoteId = [];
        foreach ($notifications as $fetched) {
            if ($fetched->createdAt < $cutoff) {
                continue;
            }
            if (!isset($byRemoteId[$fetched->remoteId]) || $fetched->seen) {
                $byRemoteId[$fetched->remoteId] = $fetched;
            }
        }

        return array_values($byRemoteId);
    }

    /**
     * @param list<FetchedNotificationModel> $retained
     * @return array<string, SocialNotification>
     */
    private function loadStored(SocialAccount $account, array $retained): array
    {
        return $this->notifications->findByRemoteIds(
            $account,
            array_map(static fn (FetchedNotificationModel $fetched): string => $fetched->remoteId, $retained),
        );
    }

    private function isAtOrBefore(\DateTimeImmutable $moment, ?\DateTimeImmutable $line): bool
    {
        return $line !== null && $moment <= $line;
    }

    private function buildRow(
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
            $fetched->seen || $this->isAtOrBefore($fetched->createdAt, $seenUntil),
        );
    }

    private function retentionCutoff(): \DateTimeImmutable
    {
        return $this->clock->now()->modify(self::RETENTION);
    }
}
