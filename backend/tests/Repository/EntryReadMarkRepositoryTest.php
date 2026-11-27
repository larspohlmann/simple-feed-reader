<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CanonicalItem;
use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CanonicalItemKind;
use App\Enum\CopyReadChange;
use App\Repository\EntryReadMarkRepository;
use App\Repository\ReadMarking;
use App\Tests\DbTestCase;
use App\Tests\Support\CanonicalItems;
use App\Tests\Support\StateMarks;

final class EntryReadMarkRepositoryTest extends DbTestCase
{
    private const string EARLIER_READ = '2026-07-11T00:00:00Z';
    private const string NOW = '2026-07-20T00:00:00Z';

    private User $user;
    private Feed $swept;
    private Feed $other;
    private Subscription $otherSubscription;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = new User('read-mark-repo@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($this->user);
        $this->swept = new Feed('https://example.com/swept.xml');
        $this->other = new Feed('https://example.com/other.xml');
        $this->entityManager->persist($this->swept);
        $this->entityManager->persist($this->other);
        $this->entityManager->persist(
            new Subscription($this->user, $this->swept, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $this->otherSubscription = new Subscription($this->user, $this->other, new \DateTimeImmutable('2026-07-01'));
        $this->entityManager->persist($this->otherSubscription);
        $this->entityManager->flush();
    }

    public function testASweepMarksTheUnreadCopiesAndLeavesAReadOneItsInstant(): void
    {
        $item = $this->persistItem('1');
        $sweptCopy = $this->persistCopy($this->swept, 'swept', $item);
        $unreadCopy = $this->persistCopy($this->other, 'unread', $item);
        $readCopy = $this->persistCopy($this->other, 'read', $item);
        $this->persistHiddenState($readCopy);

        $this->requireRepository()->spreadReadFromFeedsUntil(
            $this->buildMarking(),
            [$this->swept->requireId()],
            new \DateTimeImmutable('2026-12-31T00:00:00Z'),
        );

        self::assertTrue($this->findState($sweptCopy)?->isHidden());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $this->findState($unreadCopy)?->getHiddenAt());
        self::assertEquals(new \DateTimeImmutable(self::EARLIER_READ), $this->findState($readCopy)?->getHiddenAt());
    }

    public function testASweepEndingBeforeTheCopyLeavesItsItemAlone(): void
    {
        $item = $this->persistItem('2');
        $this->persistCopy($this->swept, 'swept', $item);
        $otherCopy = $this->persistCopy($this->other, 'other', $item);

        $this->requireRepository()->spreadReadFromFeedsUntil(
            $this->buildMarking(),
            [$this->swept->requireId()],
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );

        self::assertNull($this->findState($otherCopy));
    }

    public function testAReadReachesTheItemCopiesButNotTheEntryItself(): void
    {
        $item = $this->persistItem('3');
        $first = $this->persistCopy($this->swept, 'first', $item);
        $second = $this->persistCopy($this->other, 'second', $item);

        $this->spreadToItemCopies(CopyReadChange::Read, $first);

        self::assertNull($this->findState($first));
        self::assertTrue($this->findState($second)?->isHidden());
    }

    public function testAReadKeepsTheCopysFavouriteAndKeptMarks(): void
    {
        $item = $this->persistItem('4');
        $first = $this->persistCopy($this->swept, 'first', $item);
        $second = $this->persistCopy($this->other, 'second', $item);
        $marked = new EntryState($this->user, $second);
        StateMarks::markFavorite($marked);
        StateMarks::markKept($marked);
        $this->entityManager->persist($marked);
        $this->entityManager->flush();

        $this->spreadToItemCopies(CopyReadChange::Read, $first);

        $state = $this->findState($second);

        self::assertNotNull($state);
        self::assertTrue($state->isHidden());
        self::assertTrue($state->isFavorite());
        self::assertTrue($state->isKept());
    }

    public function testACopyInAFeedTheReaderDoesNotFollowIsLeftAlone(): void
    {
        $item = $this->persistItem('5');
        $first = $this->persistCopy($this->swept, 'first', $item);
        $second = $this->persistCopy($this->other, 'second', $item);
        $this->entityManager->remove($this->otherSubscription);
        $this->entityManager->flush();

        $this->spreadToItemCopies(CopyReadChange::Read, $first);

        self::assertNull($this->findState($second));
    }

    public function testAnUnreadOverridesTheWatermarkThatReadTheCopy(): void
    {
        $item = $this->persistItem('6');
        $first = $this->persistCopy($this->swept, 'first', $item);
        $second = $this->persistCopy($this->other, 'second', $item);
        $this->otherSubscription->setMarkedReadUntil(new \DateTimeImmutable('2026-12-31T00:00:00Z'));
        $this->entityManager->flush();

        $this->spreadToItemCopies(CopyReadChange::Unread, $first);

        $state = $this->findState($second);
        self::assertNotNull($state);
        self::assertFalse($state->isHidden());
        self::assertNull($state->getHiddenAt());
    }

    public function testAnUnreadClearsTheCopysView(): void
    {
        $item = $this->persistItem('7');
        $first = $this->persistCopy($this->swept, 'first', $item);
        $second = $this->persistCopy($this->other, 'second', $item);
        $viewed = new EntryState($this->user, $second);
        $viewed->markViewed(new \DateTimeImmutable(self::EARLIER_READ));
        $viewed->hide(new \DateTimeImmutable(self::EARLIER_READ));
        $this->entityManager->persist($viewed);
        $this->entityManager->flush();

        $this->spreadToItemCopies(CopyReadChange::Unread, $first);

        $state = $this->findState($second);

        self::assertNotNull($state);
        self::assertFalse($state->isHidden());
        self::assertFalse($state->isViewed());
        self::assertNull($state->getViewedAt());
    }

    public function testAViewHidesTheCopyAndKeepsItsEarlierReadInstant(): void
    {
        $item = $this->persistItem('8');
        $first = $this->persistCopy($this->swept, 'first', $item);
        $read = $this->persistCopy($this->other, 'read', $item);
        $unread = $this->persistCopy($this->other, 'unread', $item);
        $this->persistHiddenState($read);

        $this->spreadToItemCopies(CopyReadChange::Viewed, $first);

        $readState = $this->findState($read);

        self::assertNotNull($readState);
        self::assertTrue($readState->isViewed());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $readState->getViewedAt());
        self::assertEquals(new \DateTimeImmutable(self::EARLIER_READ), $readState->getHiddenAt());
        $unreadState = $this->findState($unread);
        self::assertNotNull($unreadState);
        self::assertTrue($unreadState->isHidden());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $unreadState->getHiddenAt());
    }

    public function testAViewOfACopyTheWatermarkReadDatesItsReadAtTheWatermark(): void
    {
        $item = $this->persistItem('9');
        $first = $this->persistCopy($this->swept, 'first', $item);
        $second = $this->persistCopy($this->other, 'second', $item);
        $this->otherSubscription->setMarkedReadUntil(new \DateTimeImmutable('2026-07-15T00:00:00Z'));
        $this->entityManager->flush();

        $this->spreadToItemCopies(CopyReadChange::Viewed, $first);

        self::assertEquals(new \DateTimeImmutable('2026-07-15T00:00:00Z'), $this->findState($second)?->getHiddenAt());
    }

    public function testAnUnviewLeavesTheCopyRead(): void
    {
        $item = $this->persistItem('10');
        $first = $this->persistCopy($this->swept, 'first', $item);
        $second = $this->persistCopy($this->other, 'second', $item);
        $viewed = new EntryState($this->user, $second);
        $viewed->markViewed(new \DateTimeImmutable(self::EARLIER_READ));
        $viewed->hide(new \DateTimeImmutable(self::EARLIER_READ));
        $this->entityManager->persist($viewed);
        $this->entityManager->flush();

        $this->spreadToItemCopies(CopyReadChange::Unviewed, $first);

        $state = $this->findState($second);

        self::assertNotNull($state);
        self::assertFalse($state->isViewed());
        self::assertTrue($state->isHidden());
        self::assertEquals(new \DateTimeImmutable(self::EARLIER_READ), $state->getHiddenAt());
    }

    public function testAnUnviewCreatesNoRowForACopyNeverViewed(): void
    {
        $item = $this->persistItem('11');
        $first = $this->persistCopy($this->swept, 'first', $item);
        $second = $this->persistCopy($this->other, 'second', $item);

        $this->spreadToItemCopies(CopyReadChange::Unviewed, $first);

        self::assertNull($this->findState($second));
    }

    public function testAnAddressReachesEveryEntryAtItLinkedOrNot(): void
    {
        $source = $this->persistArticle($this->swept, 'source', 'shared-hash');
        $copy = $this->persistArticle($this->other, 'copy', 'shared-hash');
        $stranger = $this->persistArticle($this->other, 'stranger', 'other-hash');
        $linked = $this->persistArticle($this->other, 'linked', 'shared-hash');
        $linked->linkTo($this->persistItem('12'));
        $this->entityManager->flush();

        $this->requireRepository()->spreadToAddressCopies(
            CopyReadChange::Read,
            $this->buildMarking(),
            [$source->requireId()],
        );

        self::assertTrue($this->findState($copy)?->isHidden());
        self::assertNull($this->findState($stranger));
        self::assertTrue($this->findState($linked)?->isHidden());
    }

    public function testAnArrivingCopyIsReadOnlyForTheSubscriberWhoseOwnWatermarkCoversTheOtherCopy(): void
    {
        $item = $this->persistItem('13');
        $stranger = new User('read-mark-stranger@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($stranger);
        $this->entityManager->persist(new Subscription($stranger, $this->other, new \DateTimeImmutable('2026-07-01')));
        $readCopy = $this->persistCopy($this->swept, 'covered', $item);
        $arrived = $this->persistCopy($this->other, 'arrived', $item);
        $sweptSubscription = $this->entityManager->getRepository(Subscription::class)
            ->findOneBy(['user' => $this->user, 'feed' => $this->swept]);
        self::assertInstanceOf(Subscription::class, $sweptSubscription);
        $sweptSubscription->setMarkedReadUntil(new \DateTimeImmutable('2026-12-31T00:00:00Z'));
        $this->entityManager->flush();

        $inserted = $this->requireRepository()->inheritReadFromItemCopies(
            [$arrived->requireId()],
            new \DateTimeImmutable(self::NOW),
        );

        self::assertSame(1, $inserted);
        self::assertTrue($this->findState($arrived)?->isHidden());
        self::assertNull($this->findState($readCopy));
        self::assertNull($this->entityManager->getRepository(EntryState::class)
            ->findOneForUserEntry($stranger->requireId(), $arrived->requireId()));
    }

    private function spreadToItemCopies(CopyReadChange $change, Entry $source): void
    {
        $this->requireRepository()->spreadToItemCopies($change, $this->buildMarking(), [$source->requireId()]);
    }

    private function persistItem(string $status): CanonicalItem
    {
        $item = CanonicalItems::buildItem(
            CanonicalItemKind::Mastodon,
            'https://mastodon.social/users/a/statuses/' . $status,
        );
        $this->entityManager->persist($item);

        return $item;
    }

    private function persistCopy(Feed $feed, string $guid, CanonicalItem $item): Entry
    {
        $entry = $this->persistArticle($feed, $guid, null);
        $entry->linkTo($item);
        $this->entityManager->flush();

        return $entry;
    }

    private function persistArticle(Feed $feed, string $guid, ?string $urlHash): Entry
    {
        $published = new \DateTimeImmutable('2026-07-10T00:00:00Z');
        $entry = new Entry($feed, $guid, 'https://example.com/' . $guid, $guid, $published, $published, $urlHash);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function persistHiddenState(Entry $entry): void
    {
        $state = new EntryState($this->user, $entry);
        $state->hide(new \DateTimeImmutable(self::EARLIER_READ));
        $this->entityManager->persist($state);
        $this->entityManager->flush();
    }

    private function buildMarking(): ReadMarking
    {
        return new ReadMarking($this->user->requireId(), new \DateTimeImmutable(self::NOW));
    }

    /** Clears first: the spreads are native SQL, which the identity map never sees. */
    private function findState(Entry $entry): ?EntryState
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(EntryState::class)
            ->findOneForUserEntry($this->user->requireId(), $entry->requireId());
    }

    private function requireRepository(): EntryReadMarkRepository
    {
        $repository = self::getContainer()->get(EntryReadMarkRepository::class);
        self::assertInstanceOf(EntryReadMarkRepository::class, $repository);

        return $repository;
    }
}
