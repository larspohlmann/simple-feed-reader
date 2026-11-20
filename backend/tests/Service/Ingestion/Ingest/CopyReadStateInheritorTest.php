<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Ingest;

use App\Entity\Discussion;
use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Ingestion\Ingest\Pass\FeedIngestContext;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;
use App\Service\Ingestion\Refresh\IncomingPageStore;
use App\Service\Ingestion\Refresh\Model\IncomingFeedModel;
use App\Tests\DbTestCase;
use App\Tests\Support\LemmyPostCopies;
use App\Tests\Support\LemmyPostEntries;
use App\Tests\Support\NetworkFeedSubscriptions;
use App\Tests\Support\SeedsUsers;

final class CopyReadStateInheritorTest extends DbTestCase
{
    use SeedsUsers;

    private User $reader;
    private Feed $local;
    private Feed $all;
    private Entry $localCopy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = $this->user('late-copy@example.com');
        $subscriptions = new NetworkFeedSubscriptions($this->entityManager);
        $this->local = $subscriptions->subscribe($this->reader, LemmyPostCopies::LOCAL_FEED)->getFeed();
        $this->all = $subscriptions->subscribe($this->reader, LemmyPostCopies::ALL_FEED)->getFeed();
        $this->localCopy = LemmyPostCopies::persistCopy($this->entityManager, $this->local, 'local-copy');
    }

    public function testLateCopyArrivesRead(): void
    {
        $this->hide($this->reader, $this->localCopy);
        $otherReader = $this->user('late-copy-other@example.com');
        $this->entityManager->persist(
            new Subscription($otherReader, $this->all, new \DateTimeImmutable('2026-10-01T10:00:00Z')),
        );
        $this->entityManager->flush();

        $lateCopy = $this->storeLateCopy();

        $state = $this->findState($this->reader, $lateCopy);
        self::assertNotNull($state);
        self::assertTrue($state->isHidden());
        self::assertFalse($state->isViewed());
        self::assertNull($this->findState($otherReader, $lateCopy), 'Who never read the post sees the copy unread.');
    }

    public function testLateCopyOfAPostSweptReadArrivesRead(): void
    {
        $this->sweepLocal();

        $lateCopy = $this->storeLateCopy();

        self::assertTrue($this->findState($this->reader, $lateCopy)?->isHidden());
    }

    public function testLateCopyOfAPostMarkedUnreadUnderTheWatermarkArrivesUnread(): void
    {
        $this->sweepLocal();
        $state = new EntryState($this->reader, $this->localCopy);
        $state->markUnread();
        $this->entityManager->persist($state);
        $this->entityManager->flush();

        $lateCopy = $this->storeLateCopy();

        self::assertNull($this->findState($this->reader, $lateCopy));
    }

    public function testLateCopyOfAnUnreadPostArrivesUnread(): void
    {
        $lateCopy = $this->storeLateCopy();

        self::assertNull($this->findState($this->reader, $lateCopy));
    }

    private function storeLateCopy(): Entry
    {
        $parsed = new ParsedEntryModel(
            guid: 'all-copy',
            url: null,
            headline: Headline::fromFeed('A post title'),
            author: null,
            summary: null,
            contentHtml: '<p>body</p>',
            publishedAt: new \DateTimeImmutable('2026-11-14T00:00:00Z'),
        );
        $this->requirePageStore()->store(
            $this->all,
            IncomingFeedModel::parsed(new ParsedFeedModel('All', null, null, null, [
                $parsed->asDiscussionThread(Discussion::platformThread(LemmyPostEntries::PAGE)),
            ])),
            new FeedIngestContext(new \DateTimeImmutable('2026-11-16T00:00:00Z'), null),
        );
        $lateCopy = $this->entityManager->getRepository(Entry::class)
            ->findOneBy(['feed' => $this->all, 'guid.value' => 'all-copy']);
        self::assertInstanceOf(Entry::class, $lateCopy);
        self::assertSame(
            $this->localCopy->getCanonicalItem()?->requireId(),
            $lateCopy->getCanonicalItem()?->requireId(),
        );

        return $lateCopy;
    }

    private function sweepLocal(): void
    {
        $subscription = $this->entityManager->getRepository(Subscription::class)
            ->findOneBy(['user' => $this->reader, 'feed' => $this->local]);
        self::assertNotNull($subscription);
        $subscription->setMarkedReadUntil(new \DateTimeImmutable('2026-11-15T00:00:00Z'));
        $this->entityManager->flush();
    }

    private function hide(User $reader, Entry $entry): void
    {
        $state = new EntryState($reader, $entry);
        $state->hide(new \DateTimeImmutable('2026-11-15T00:00:00Z'));
        $this->entityManager->persist($state);
        $this->entityManager->flush();
    }

    /** Clears first: the inherited state is native SQL, which the identity map never sees. */
    private function findState(User $reader, Entry $entry): ?EntryState
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(EntryState::class)
            ->findOneForUserEntry($reader->requireId(), $entry->requireId());
    }

    private function requirePageStore(): IncomingPageStore
    {
        $pageStore = self::getContainer()->get(IncomingPageStore::class);
        self::assertInstanceOf(IncomingPageStore::class, $pageStore);

        return $pageStore;
    }
}
