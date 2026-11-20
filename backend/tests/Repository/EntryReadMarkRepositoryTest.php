<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CanonicalItemKind;
use App\Repository\EntryReadMarkRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\CanonicalItems;

final class EntryReadMarkRepositoryTest extends DbTestCase
{
    private User $user;
    private Feed $swept;
    private Feed $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User('read-mark-repo@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->swept = new Feed('https://example.com/swept.xml');
        $this->other = new Feed('https://example.com/other.xml');
        $this->entityManager->persist($this->swept);
        $this->entityManager->persist($this->other);
        foreach ([$this->swept, $this->other] as $feed) {
            $this->entityManager->persist(
                new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
            );
        }
        $this->entityManager->flush();
    }

    public function testASweepReachesOnlyTheUnreadCopies(): void
    {
        $item = CanonicalItems::buildItem(CanonicalItemKind::Mastodon, 'https://mastodon.social/users/a/statuses/1');
        $this->entityManager->persist($item);
        $sweptCopy = $this->persistCopy($this->swept, 'swept');
        $unreadCopy = $this->persistCopy($this->other, 'unread');
        $readCopy = $this->persistCopy($this->other, 'read');
        foreach ([$sweptCopy, $unreadCopy, $readCopy] as $copy) {
            $copy->linkTo($item);
        }
        $this->entityManager->persist($this->hiddenState($readCopy));
        $this->entityManager->flush();

        $ids = $this->repository()->findUnreadCopyIdsForSweep(
            $this->user->requireId(),
            [$this->swept->requireId()],
            new \DateTimeImmutable('2026-12-31T00:00:00Z'),
        );

        sort($ids);
        self::assertSame([$sweptCopy->requireId(), $unreadCopy->requireId()], $ids);
    }

    public function testCopiesOfTheGivenEntriesAreFoundExcludingTheEntriesThemselves(): void
    {
        $item = CanonicalItems::buildItem(CanonicalItemKind::Mastodon, 'https://mastodon.social/users/a/statuses/2');
        $this->entityManager->persist($item);
        $first = $this->persistCopy($this->swept, 'first');
        $second = $this->persistCopy($this->other, 'second');
        $first->linkTo($item);
        $second->linkTo($item);
        $this->entityManager->flush();

        $ids = $this->repository()->findCopyIdsForUser($this->user->requireId(), [$first->requireId()]);

        self::assertSame([$second->requireId()], $ids);
    }

    private function persistCopy(Feed $feed, string $guid): Entry
    {
        $now = new \DateTimeImmutable('2026-07-10T00:00:00Z');
        $entry = new Entry($feed, $guid, 'https://example.com/' . $guid, $guid, $now, $now);
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function hiddenState(Entry $entry): EntryState
    {
        $state = new EntryState($this->user, $entry);
        $state->hide(new \DateTimeImmutable('2026-07-11T00:00:00Z'));

        return $state;
    }

    private function repository(): EntryReadMarkRepository
    {
        $repository = self::getContainer()->get(EntryReadMarkRepository::class);
        self::assertInstanceOf(EntryReadMarkRepository::class, $repository);

        return $repository;
    }
}
