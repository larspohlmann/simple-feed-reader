<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Ingest;

use App\Entity\CanonicalItem;
use App\Entity\Discussion;
use App\Entity\DiscussionCounts;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Service\Ingestion\Ingest\EntryIngestor;
use App\Service\Ingestion\Ingest\Pass\FeedIngestContext;
use App\Service\Ingestion\Ingest\StoredEntryRefresher;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;

final class CanonicalItemWriterTest extends DbTestCase
{
    private const string POST = 'https://lemmy.world/post/7';
    private const string OTHER_POST = 'https://lemmy.world/post/8';

    public function testTwoCopiesInOneFlushShareOneItem(): void
    {
        $first = $this->feed('https://example.com/a.xml');
        $second = $this->feed('https://example.com/b.xml');

        [$copyA] = $this->ingestor()->ingest($first, self::feedOf(self::post(3, 2)), self::context());
        [$copyB] = $this->ingestor()->ingest($second, self::feedOf(self::post(3, 2)), self::context());
        $this->entityManager->flush();

        self::assertNotNull($copyA->getCanonicalItem());
        self::assertSame($copyA->getCanonicalItem()->requireId(), $copyB->getCanonicalItem()?->requireId());
        self::assertSame(1, $this->itemRowCount());
    }

    public function testAnItemInsertedThisRequestGetsItsCountsFlushed(): void
    {
        $feed = $this->feed('https://example.com/a.xml');
        $this->ingestor()->ingest($feed, self::feedOf(self::post(3, 2)), self::context());
        $this->entityManager->flush();
        $this->entityManager->clear();

        $item = $this->onlyItem();
        self::assertSame(3, $item->getScore());
        self::assertSame(2, $item->getCommentCount());
    }

    public function testRefreshRecordsNewestCounts(): void
    {
        $first = $this->feed('https://example.com/a.xml');
        $second = $this->feed('https://example.com/b.xml');
        [$copyA] = $this->ingestor()->ingest($first, self::feedOf(self::post(3, 2)), self::context());
        $this->ingestor()->ingest($second, self::feedOf(self::post(3, 2)), self::context());
        $this->entityManager->flush();
        $copyAId = $copyA->requireId();
        $this->entityManager->clear();

        $this->refresher()->refreshStoredEntries($this->reloaded($second), self::feedOf(self::post(5, 4)));
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame(5, $this->onlyItem()->getScore());
        self::assertSame(4, $this->entityManager->find(Entry::class, $copyAId)?->discussionCounts()->getCommentCount());
    }

    public function testRefreshLinksAStoredEntryThatHasNoItemYet(): void
    {
        $feed = $this->feed('https://example.com/a.xml');
        $now = new \DateTimeImmutable('2026-11-20T12:00:00Z');
        $stored = new Entry($feed, self::POST, null, 'Post', $now, $now);
        $stored->setDiscussion(Discussion::platformThread(self::POST));
        $stored->discussionCounts()->recordCounts(DiscussionCounts::fromScoreAndComments(null, 9));
        $stored->discussionCounts()->recordSelfReplyCount(2);
        $this->entityManager->persist($stored);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->refresher()->refreshStoredEntries($this->reloaded($feed), self::feedOf(self::post(5, null)));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $item = $this->onlyItem();
        self::assertSame(5, $item->getScore());
        self::assertSame(9, $item->getCommentCount());
        self::assertSame(2, $item->getSelfReplyCount());
    }

    public function testRefreshingLinkedEntriesReadsNoItemOnItsOwn(): void
    {
        $feed = $this->feed('https://example.com/a.xml');
        $twoPosts = self::feedOf(self::post(1, 1), self::post(2, 2, self::OTHER_POST));
        $this->ingestor()->ingest($feed, $twoPosts, self::context());
        $this->entityManager->flush();
        $this->entityManager->clear();
        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $this->refresher()->refreshStoredEntries(
            $this->reloaded($feed),
            self::feedOf(self::post(5, 5), self::post(6, 6, self::OTHER_POST)),
        );

        self::assertSame([], $recorder->queriesMatching('from canonical_item'));
    }

    public function testAnEntryNoRuleClaimsGetsNoItem(): void
    {
        [$article] = $this->ingestor()->ingest(
            $this->feed('https://example.com/feed.xml'),
            self::feedOf(
                new ParsedEntryModel('a1', 'https://example.com/a1', Headline::fromFeed('A'), null, null, null, null),
            ),
            self::context(),
        );
        $this->entityManager->flush();

        self::assertNull($article->getCanonicalItem());
        self::assertSame(0, $this->itemRowCount());
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    private function reloaded(Feed $feed): Feed
    {
        $reloaded = $this->entityManager->find(Feed::class, $feed->requireId());
        self::assertInstanceOf(Feed::class, $reloaded);

        return $reloaded;
    }

    private static function post(?int $score, ?int $commentCount, string $page = self::POST): ParsedEntryModel
    {
        return new ParsedEntryModel($page, $page, Headline::fromFeed('Post'), null, null, null, null)
            ->asDiscussionThread(Discussion::platformThread($page))
            ->withDiscussionCounts(DiscussionCounts::fromScoreAndComments($score, $commentCount));
    }

    private static function feedOf(ParsedEntryModel ...$entries): ParsedFeedModel
    {
        return new ParsedFeedModel('T', null, null, null, array_values($entries));
    }

    private static function context(): FeedIngestContext
    {
        return new FeedIngestContext(new \DateTimeImmutable('2026-11-20T12:00:00Z'), null);
    }

    private function onlyItem(): CanonicalItem
    {
        $items = $this->entityManager->getRepository(CanonicalItem::class)->findAll();
        self::assertCount(1, $items);

        return $items[0];
    }

    private function itemRowCount(): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM canonical_item');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function ingestor(): EntryIngestor
    {
        $ingestor = self::getContainer()->get(EntryIngestor::class);
        self::assertInstanceOf(EntryIngestor::class, $ingestor);

        return $ingestor;
    }

    private function refresher(): StoredEntryRefresher
    {
        $refresher = self::getContainer()->get(StoredEntryRefresher::class);
        self::assertInstanceOf(StoredEntryRefresher::class, $refresher);

        return $refresher;
    }
}
