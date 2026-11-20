<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\Reading;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Library\Reading\EntryReadMarker;
use App\Tests\DbTestCase;
use App\Tests\Support\LemmyPostCopies;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\SeedsUsers;

final class EntryReadMarkerTest extends DbTestCase
{
    use ReloadsEntities;
    use SeedsUsers;

    private User $reader;
    private Feed $feed;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = $this->user('marker@example.com');
        $this->feed = new Feed('https://example.com/marker.xml');
        $this->entityManager->persist($this->feed);
        $this->subscription = new Subscription(
            $this->reader,
            $this->feed,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $this->entityManager->persist($this->subscription);
        $this->entityManager->flush();
    }

    public function testMarkingEntriesCreatesAHiddenRowWhereNoneExists(): void
    {
        $entry = $this->entry('no-row', '2026-07-05T00:00:00Z');

        $this->marker()->markEntriesRead($this->reader->requireId(), [$entry->requireId()]);

        $state = $this->stateOf($entry);
        self::assertNotNull($state);
        self::assertTrue($state->isHidden());
    }

    public function testMarkingEntriesFlipsAnExplicitUnreadAndStampsIt(): void
    {
        $entry = $this->entry('unread', '2026-07-05T00:00:00Z');
        $this->explicitlyUnread($entry);

        $this->marker()->markEntriesRead($this->reader->requireId(), [$entry->requireId()]);

        $state = $this->stateOf($entry);
        self::assertNotNull($state);
        self::assertTrue($state->isHidden());
        self::assertNotNull($state->getHiddenAt());
    }

    public function testAStateLoadedBeforeMarkingReadsAsHiddenWhenFetchedStraightAfter(): void
    {
        $entry = $this->entry('loaded', '2026-07-05T00:00:00Z');
        $this->explicitlyUnread($entry);

        $this->marker()->markEntriesRead($this->reader->requireId(), [$entry->requireId()]);

        $state = $this->entityManager->getRepository(EntryState::class)
            ->findOneForUserEntry($this->reader->requireId(), $entry->requireId());
        self::assertNotNull($state);
        self::assertTrue($state->isHidden());
    }

    public function testMarkingEntriesLeavesEveryOtherEntryAlone(): void
    {
        $marked = $this->entry('marked', '2026-07-05T00:00:00Z');
        $other = $this->entry('other', '2026-07-05T00:00:00Z');
        $this->explicitlyUnread($other);

        $this->marker()->markEntriesRead($this->reader->requireId(), [$marked->requireId()]);

        $state = $this->stateOf($other);
        self::assertNotNull($state);
        self::assertFalse($state->isHidden());
    }

    public function testMarkingSubscriptionsAdvancesTheWatermarkAndFlipsUnreadsUpToUntil(): void
    {
        $covered = $this->entry('covered', '2026-07-05T00:00:00Z');
        $newer = $this->entry('newer', '2026-07-20T00:00:00Z');
        $this->explicitlyUnread($covered);
        $this->explicitlyUnread($newer);

        $this->marker()->markSubscriptionsReadUntil(
            $this->reader->requireId(),
            [$this->subscription],
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );

        self::assertSame('2026-07-10T00:00:00+00:00', $this->watermark());
        self::assertTrue($this->stateOf($covered)?->isHidden());
        self::assertFalse($this->stateOf($newer)?->isHidden());
    }

    public function testMarkingSubscriptionsMovesAnEarlierWatermarkForward(): void
    {
        $this->subscription->setMarkedReadUntil(new \DateTimeImmutable('2026-07-05T00:00:00Z'));
        $this->entityManager->flush();

        $this->marker()->markSubscriptionsReadUntil(
            $this->reader->requireId(),
            [$this->subscription],
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );

        self::assertSame('2026-07-10T00:00:00+00:00', $this->watermark());
    }

    public function testMarkingSubscriptionsNeverMovesAWatermarkBack(): void
    {
        $this->subscription->setMarkedReadUntil(new \DateTimeImmutable('2026-07-15T00:00:00Z'));
        $this->entityManager->flush();

        $this->marker()->markSubscriptionsReadUntil(
            $this->reader->requireId(),
            [$this->subscription],
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );

        self::assertSame('2026-07-15T00:00:00+00:00', $this->watermark());
    }

    public function testScrollPastMarksTheCopy(): void
    {
        $copies = LemmyPostCopies::persistPair($this->entityManager, $this->reader);

        $this->marker()->markEntriesRead($this->reader->requireId(), [$copies->localCopy->requireId()]);

        self::assertTrue($this->stateOf($copies->allCopy)?->isHidden());
    }

    public function testMarkingACopyAndItsCopyTogetherMarksBothOnce(): void
    {
        $copies = LemmyPostCopies::persistPair($this->entityManager, $this->reader);

        $this->marker()->markEntriesRead(
            $this->reader->requireId(),
            [$copies->localCopy->requireId(), $copies->allCopy->requireId()],
        );

        self::assertTrue($this->stateOf($copies->localCopy)?->isHidden());
        self::assertTrue($this->stateOf($copies->allCopy)?->isHidden());
    }

    public function testCopyInUnsubscribedFeedGetsNoState(): void
    {
        $copies = LemmyPostCopies::persistPair($this->entityManager, $this->reader);
        $this->entityManager->remove($copies->all);
        $this->entityManager->flush();

        $this->marker()->markEntriesRead($this->reader->requireId(), [$copies->localCopy->requireId()]);

        self::assertNull($this->stateOf($copies->allCopy));
    }

    public function testASweepMarksTheCopyInAnotherFeed(): void
    {
        $copies = LemmyPostCopies::persistPair($this->entityManager, $this->reader);

        $this->marker()->markSubscriptionsReadUntil(
            $this->reader->requireId(),
            [$copies->local],
            new \DateTimeImmutable('2026-11-15T00:00:00Z'),
        );

        self::assertTrue($this->stateOf($copies->allCopy)?->isHidden());
        self::assertNull($this->stateOf($copies->localCopy), 'The watermark covers the swept copy.');
    }

    public function testASweepEndingBeforeThePostLeavesItsCopyAlone(): void
    {
        $copies = LemmyPostCopies::persistPair($this->entityManager, $this->reader);

        $this->marker()->markSubscriptionsReadUntil(
            $this->reader->requireId(),
            [$copies->local],
            new \DateTimeImmutable('2026-11-13T00:00:00Z'),
        );

        self::assertNull($this->stateOf($copies->allCopy));
    }

    public function testASweepLeavesACopyAlreadyReadByItsOwnWatermarkWithoutAState(): void
    {
        $copies = LemmyPostCopies::persistPair($this->entityManager, $this->reader);
        $copies->all->setMarkedReadUntil(new \DateTimeImmutable('2026-11-15T00:00:00Z'));
        $this->entityManager->flush();

        $this->marker()->markSubscriptionsReadUntil(
            $this->reader->requireId(),
            [$copies->local],
            new \DateTimeImmutable('2026-11-15T00:00:00Z'),
        );

        self::assertNull($this->stateOf($copies->allCopy));
    }

    private function marker(): EntryReadMarker
    {
        $marker = self::getContainer()->get(EntryReadMarker::class);
        self::assertInstanceOf(EntryReadMarker::class, $marker);

        return $marker;
    }

    private function entry(string $guid, string $effectiveDate): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            null,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable($effectiveDate),
        );
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function explicitlyUnread(Entry $entry): void
    {
        $state = new EntryState($this->reader, $entry);
        $state->markUnread();
        $this->entityManager->persist($state);
        $this->entityManager->flush();
    }

    /** Clears first: the marks are bulk DQL, which the identity map never sees. */
    private function stateOf(Entry $entry): ?EntryState
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(EntryState::class)
            ->findOneForUserEntry($this->reader->requireId(), $entry->requireId());
    }

    private function watermark(): ?string
    {
        return $this->reload($this->subscription)->getMarkedReadUntil()?->format(\DateTimeInterface::ATOM);
    }
}
