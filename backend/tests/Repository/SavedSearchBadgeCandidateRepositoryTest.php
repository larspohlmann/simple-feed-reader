<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\DuplicateCollapseDql;
use App\Repository\EntryScopePredicates;
use App\Repository\SavedSearchBadgeCandidateRepository;
use App\Tests\DbTestCase;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The two database reads the engine badge walk needs: a boundary id's
 * effectiveDate for its next keyset cursor, and a candidate id set narrowed
 * to unread/subscribed/#496-collapsed.
 */
final class SavedSearchBadgeCandidateRepositoryTest extends DbTestCase
{
    private User $user;
    private Feed $feed;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('badges@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);

        $this->subscription = new Subscription(
            $this->user,
            $this->feed,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $this->em->persist($this->subscription);
        $this->em->flush();
    }

    /** @param positive-int|null $idFilterChunk */
    private function repo(?int $idFilterChunk = null): SavedSearchBadgeCandidateRepository
    {
        $container = self::getContainer();
        /** @var ManagerRegistry $registry */
        $registry = $container->get(ManagerRegistry::class);
        /** @var EntryScopePredicates $scope */
        $scope = $container->get(EntryScopePredicates::class);
        /** @var DuplicateCollapseDql $collapse */
        $collapse = $container->get(DuplicateCollapseDql::class);

        return $idFilterChunk === null
            ? new SavedSearchBadgeCandidateRepository($registry, $scope, $collapse)
            : new SavedSearchBadgeCandidateRepository($registry, $scope, $collapse, $idFilterChunk);
    }

    private function entry(string $guid, string $effectiveDate, ?Feed $feed = null): Entry
    {
        $entry = new Entry(
            $feed ?? $this->feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable($effectiveDate),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function hide(Entry $entry): void
    {
        $state = new EntryState($this->user, $entry);
        $state->setIsHidden(true);
        $this->em->persist($state);
        $this->em->flush();
    }

    public function testEffectiveDatesByIdsAnswersTheStoredInstantPerId(): void
    {
        $a = $this->entry('a', '2026-07-10T00:00:00Z');
        $b = $this->entry('b', '2026-07-11T00:00:00Z');

        $dates = $this->repo()->effectiveDatesByIds([$a->getId() ?? 0, $b->getId() ?? 0]);

        self::assertEquals(new \DateTimeImmutable('2026-07-10T00:00:00Z'), $dates[$a->getId() ?? 0]);
        self::assertEquals(new \DateTimeImmutable('2026-07-11T00:00:00Z'), $dates[$b->getId() ?? 0]);
    }

    public function testEffectiveDatesByIdsWithNoIdsNeedsNoQuery(): void
    {
        self::assertSame([], $this->repo()->effectiveDatesByIds([]));
    }

    public function testUnreadCollapsedSubscribedIdsDropsReadEntries(): void
    {
        $unread = $this->entry('a', '2026-07-10T00:00:00Z');
        $read = $this->entry('b', '2026-07-11T00:00:00Z');
        $this->hide($read);

        $ids = $this->repo()->unreadCollapsedSubscribedIds(
            [$unread->getId() ?? 0, $read->getId() ?? 0],
            $this->user->getId() ?? 0,
        );

        self::assertSame([$unread->getId()], $ids);
    }

    public function testUnreadCollapsedSubscribedIdsRespectsMarkedReadUntil(): void
    {
        $before = $this->entry('a', '2026-07-05T00:00:00Z');
        $after = $this->entry('b', '2026-07-15T00:00:00Z');

        $this->subscription->setMarkedReadUntil(new \DateTimeImmutable('2026-07-10T00:00:00Z'));
        $this->em->flush();

        $ids = $this->repo()->unreadCollapsedSubscribedIds(
            [$before->getId() ?? 0, $after->getId() ?? 0],
            $this->user->getId() ?? 0,
        );

        self::assertSame([$after->getId()], $ids);
    }

    public function testUnreadCollapsedSubscribedIdsDropsAnUnsubscribedFeedsEntry(): void
    {
        $otherFeed = new Feed('https://example.com/other.xml');
        $otherFeed->setTitle('Other');
        $this->em->persist($otherFeed);
        $this->em->flush();

        $foreign = $this->entry('a', '2026-07-10T00:00:00Z', $otherFeed);

        $ids = $this->repo()->unreadCollapsedSubscribedIds([$foreign->getId() ?? 0], $this->user->getId() ?? 0);

        self::assertSame([], $ids);
    }

    public function testUnreadCollapsedSubscribedIdsCollapsesADuplicateToItsLowestId(): void
    {
        $urlHash = hash('sha256', 'https://example.com/same-article');
        $first = new Entry(
            $this->feed,
            'dup-a',
            'https://example.com/same-article',
            'Same article',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
            $urlHash,
        );
        $this->em->persist($first);
        $this->em->flush();

        $second = new Entry(
            $this->feed,
            'dup-b',
            'https://example.com/same-article',
            'Same article',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-11T00:00:00Z'),
            $urlHash,
        );
        $this->em->persist($second);
        $this->em->flush();

        $ids = $this->repo()->unreadCollapsedSubscribedIds(
            [$first->getId() ?? 0, $second->getId() ?? 0],
            $this->user->getId() ?? 0,
        );

        self::assertSame([$first->getId()], $ids);
    }

    public function testUnreadCollapsedSubscribedIdsWithNoIdsNeedsNoQuery(): void
    {
        self::assertSame([], $this->repo()->unreadCollapsedSubscribedIds([], $this->user->getId() ?? 0));
    }

    public function testUnreadCollapsedSubscribedIdsSurvivesAChunkBoundary(): void
    {
        $a = $this->entry('a', '2026-07-10T00:00:00Z');
        $b = $this->entry('b', '2026-07-11T00:00:00Z');

        $ids = $this->repo(idFilterChunk: 1)->unreadCollapsedSubscribedIds(
            [$a->getId() ?? 0, $b->getId() ?? 0],
            $this->user->getId() ?? 0,
        );

        self::assertSame([$a->getId(), $b->getId()], $ids);
    }
}
