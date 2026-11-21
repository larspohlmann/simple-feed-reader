<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Ingest;

use App\Entity\CanonicalItem;
use App\Entity\Discussion;
use App\Entity\DiscussionCounts;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Enum\SocialNetwork;
use App\Service\Foundation\CanonicalItem\CanonicalItemLinker;
use App\Service\Ingestion\Ingest\CanonicalItemWriter;
use App\Service\Ingestion\Ingest\CrossPostKeyRule\CrossPostKeyRuleInterface;
use App\Service\Ingestion\Ingest\CrossPostKeys;
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
    private const string AUTHOR = 'https://lemmy.world/u/perfectdark';
    private const string OTHER_POST = 'https://lemmy.world/post/8';

    public function testTwoCopiesInOneFlushShareOneItem(): void
    {
        $first = $this->buildFeed('https://example.com/a.xml');
        $second = $this->buildFeed('https://example.com/b.xml');

        [$copyA] = $this->requireIngestor()->ingest(
            $first,
            self::buildParsedFeed(self::buildPost(3, 2)),
            self::buildContext(),
        );
        [$copyB] = $this->requireIngestor()->ingest(
            $second,
            self::buildParsedFeed(self::buildPost(3, 2)),
            self::buildContext(),
        );
        $this->entityManager->flush();

        self::assertNotNull($copyA->getCanonicalItem());
        self::assertSame($copyA->getCanonicalItem()->requireId(), $copyB->getCanonicalItem()?->requireId());
        self::assertSame(1, $this->countItemRows());
    }

    public function testAPageOfSeveralNewPostsLinksEachToItsOwnItem(): void
    {
        $feed = $this->buildFeed('https://example.com/a.xml');
        $pages = array_map(static fn (int $number): string => 'https://lemmy.world/post/' . $number, range(20, 24));

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $entries = $this->requireIngestor()->ingest(
            $feed,
            self::buildParsedFeed(...array_map(
                static fn (string $page): ParsedEntryModel => self::buildPost(1, 1, $page),
                $pages,
            )),
            self::buildContext(),
        );
        $this->entityManager->flush();

        self::assertCount(
            3,
            array_merge(
                $recorder->queriesMatching('from canonical_item'),
                $recorder->queriesMatching('into canonical_item'),
            ),
        );

        $itemIds = array_map(static fn (Entry $entry): ?int => $entry->getCanonicalItem()?->requireId(), $entries);
        self::assertCount(5, array_unique($itemIds));
        self::assertNotContains(null, $itemIds);
        self::assertSame(5, $this->countItemRows());
    }

    public function testAnItemInsertedThisRequestGetsItsCountsFlushed(): void
    {
        $feed = $this->buildFeed('https://example.com/a.xml');
        $this->requireIngestor()->ingest($feed, self::buildParsedFeed(self::buildPost(3, 2)), self::buildContext());
        $this->entityManager->flush();
        $this->entityManager->clear();

        $item = $this->requireOnlyItem();
        self::assertSame(3, $item->getScore());
        self::assertSame(2, $item->getCommentCount());
    }

    public function testRefreshRecordsNewestCounts(): void
    {
        $first = $this->buildFeed('https://example.com/a.xml');
        $second = $this->buildFeed('https://example.com/b.xml');
        [$copyA] = $this->requireIngestor()->ingest(
            $first,
            self::buildParsedFeed(self::buildPost(3, 2)),
            self::buildContext(),
        );
        $this->requireIngestor()->ingest($second, self::buildParsedFeed(self::buildPost(3, 2)), self::buildContext());
        $this->entityManager->flush();
        $copyAId = $copyA->requireId();
        $this->entityManager->clear();

        $this->requireRefresher()->refreshStoredEntries(
            $this->reloadFeed($second),
            self::buildParsedFeed(self::buildPost(5, 4)),
        );
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame(5, $this->requireOnlyItem()->getScore());
        self::assertSame(
            4,
            $this->entityManager->find(Entry::class, $copyAId)?->selectDiscussionCounts()->getCommentCount(),
        );
    }

    public function testRefreshLinksAStoredEntryThatHasNoItemYet(): void
    {
        $feed = $this->buildFeed('https://example.com/a.xml');
        $now = new \DateTimeImmutable('2026-11-20T12:00:00Z');
        $stored = new Entry($feed, self::POST, null, 'Post', $now, $now);
        $stored->setDiscussion(Discussion::platformThread(self::POST));
        $stored->selectDiscussionCounts()->recordCounts(DiscussionCounts::fromScoreAndComments(null, 9));
        $stored->selectDiscussionCounts()->recordSelfReplyCount(2);
        $this->entityManager->persist($stored);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->requireRefresher()->refreshStoredEntries(
            $this->reloadFeed($feed),
            self::buildParsedFeed(self::buildPost(5, null)),
        );
        $this->entityManager->flush();
        $this->entityManager->clear();

        $item = $this->requireOnlyItem();
        self::assertSame(5, $item->getScore());
        self::assertSame(9, $item->getCommentCount());
        self::assertSame(2, $item->getSelfReplyCount());
    }

    public function testRefreshingLinkedEntriesReadsNoItemOnItsOwn(): void
    {
        $feed = $this->buildFeed('https://example.com/a.xml');
        $twoPosts = self::buildParsedFeed(self::buildPost(1, 1), self::buildPost(2, 2, self::OTHER_POST));
        $this->requireIngestor()->ingest($feed, $twoPosts, self::buildContext());
        $this->entityManager->flush();
        $this->entityManager->clear();
        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $this->requireRefresher()->refreshStoredEntries(
            $this->reloadFeed($feed),
            self::buildParsedFeed(self::buildPost(5, 5), self::buildPost(6, 6, self::OTHER_POST)),
        );

        self::assertSame([], $recorder->queriesMatching('from canonical_item'));
    }

    public function testAnEntryNoRuleClaimsGetsNoItem(): void
    {
        [$article] = $this->requireIngestor()->ingest(
            $this->buildFeed('https://example.com/feed.xml'),
            self::buildParsedFeed(
                new ParsedEntryModel('a1', 'https://example.com/a1', Headline::fromFeed('A'), null, null, null, null),
            ),
            self::buildContext(),
        );
        $this->entityManager->flush();

        self::assertNull($article->getCanonicalItem());
        self::assertSame(0, $this->countItemRows());
    }

    public function testTwoCommunitiesOfOneLinkPostShareAHash(): void
    {
        $feed = $this->buildFeed('https://example.com/a.xml');

        [$first, $second] = $this->requireIngestor()->ingest(
            $feed,
            self::buildParsedFeed(
                self::buildLinkPost(self::POST, 'https://x.example/a'),
                self::buildLinkPost(self::OTHER_POST, 'https://x.example/a?utm_source=l'),
            ),
            self::buildContext(),
        );
        $this->entityManager->flush();

        self::assertNotSame($first->getCanonicalItem(), $second->getCanonicalItem());
        self::assertNotNull($first->getCanonicalItem()?->getCrossPostHash());
        self::assertSame(
            $first->getCanonicalItem()->getCrossPostHash(),
            $second->getCanonicalItem()?->getCrossPostHash(),
        );
    }

    public function testALaterCopyKeepsTheFirstCopysHash(): void
    {
        $first = $this->buildFeed('https://example.com/a.xml');
        $second = $this->buildFeed('https://example.com/b.xml');
        [$copyA] = $this->requireIngestor()->ingest(
            $first,
            self::buildParsedFeed(self::buildLinkPost(self::POST, 'https://x.example/a')),
            self::buildContext(),
        );
        $this->entityManager->flush();
        $hash = $copyA->getCanonicalItem()?->getCrossPostHash();

        [$copyB] = $this->requireIngestor()->ingest(
            $second,
            self::buildParsedFeed(self::buildLinkPost(self::POST, 'https://x.example/other')),
            self::buildContext(),
        );
        $this->entityManager->flush();

        self::assertNotNull($hash);
        self::assertSame($copyA->getCanonicalItem(), $copyB->getCanonicalItem());
        self::assertSame($hash, $copyB->getCanonicalItem()->getCrossPostHash());
    }

    public function testAnItemThatAlreadyHasAHashIsNotAskedForOneAgain(): void
    {
        $post = self::buildLinkPost(self::POST, 'https://x.example/a');
        [$first] = $this->requireIngestor()->ingest(
            $this->buildFeed('https://example.com/a.xml'),
            self::buildParsedFeed($post),
            self::buildContext(),
        );
        $now = new \DateTimeImmutable('2026-11-20T12:00:00Z');
        $laterCopy = new Entry($this->buildFeed('https://example.com/b.xml'), self::POST, null, 'Post', $now, $now);
        $laterCopy->setDiscussion(Discussion::platformThread(self::POST));
        $rule = new class implements CrossPostKeyRuleInterface {
            public int $asked = 0;

            public function findHash(CanonicalItem $item, ParsedEntryModel $parsed): string
            {
                ++$this->asked;

                return 'other';
            }
        };
        $linker = self::getContainer()->get(CanonicalItemLinker::class);
        self::assertInstanceOf(CanonicalItemLinker::class, $linker);

        new CanonicalItemWriter($linker, new CrossPostKeys([$rule]))->attach([[$laterCopy, $post]]);

        self::assertSame($first->getCanonicalItem(), $laterCopy->getCanonicalItem());
        self::assertSame(0, $rule->asked);
    }

    public function testAMastodonItemGetsNoHash(): void
    {
        $post = 'https://mastodon.social/@someone/115000000000000001';
        $feed = $this->buildFeed('https://example.com/a.xml');
        $feed->classifyAs(SocialNetwork::Mastodon);
        [$status] = $this->requireIngestor()->ingest(
            $feed,
            self::buildParsedFeed(
                new ParsedEntryModel(
                    $post,
                    $post,
                    Headline::fromFeed('Status'),
                    null,
                    null,
                    null,
                    null,
                    authorUrl: self::AUTHOR,
                )
                    ->asDiscussionThread(Discussion::platformThread($post))
                    ->withLinkedUrl('https://x.example/a'),
            ),
            self::buildContext(),
        );
        $this->entityManager->flush();

        self::assertNotNull($status->getCanonicalItem());
        self::assertNull($status->getCanonicalItem()->getCrossPostHash());
    }

    private function buildFeed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    private function reloadFeed(Feed $feed): Feed
    {
        $reloaded = $this->entityManager->find(Feed::class, $feed->requireId());
        self::assertInstanceOf(Feed::class, $reloaded);

        return $reloaded;
    }

    private static function buildPost(?int $score, ?int $commentCount, string $page = self::POST): ParsedEntryModel
    {
        return new ParsedEntryModel($page, $page, Headline::fromFeed('Post'), null, null, null, null)
            ->asDiscussionThread(Discussion::platformThread($page))
            ->withDiscussionCounts(DiscussionCounts::fromScoreAndComments($score, $commentCount));
    }

    private static function buildLinkPost(string $page, string $linkedUrl): ParsedEntryModel
    {
        return new ParsedEntryModel(
            $page,
            $page,
            Headline::fromFeed('Post'),
            null,
            null,
            null,
            null,
            authorUrl: self::AUTHOR,
        )
            ->asDiscussionThread(Discussion::platformThread($page))
            ->withLinkedUrl($linkedUrl);
    }

    private static function buildParsedFeed(ParsedEntryModel ...$entries): ParsedFeedModel
    {
        return new ParsedFeedModel('T', null, null, null, array_values($entries));
    }

    private static function buildContext(): FeedIngestContext
    {
        return new FeedIngestContext(new \DateTimeImmutable('2026-11-20T12:00:00Z'), null);
    }

    private function requireOnlyItem(): CanonicalItem
    {
        $items = $this->entityManager->getRepository(CanonicalItem::class)->findAll();
        self::assertCount(1, $items);

        return $items[0];
    }

    private function countItemRows(): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM canonical_item');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function requireIngestor(): EntryIngestor
    {
        $ingestor = self::getContainer()->get(EntryIngestor::class);
        self::assertInstanceOf(EntryIngestor::class, $ingestor);

        return $ingestor;
    }

    private function requireRefresher(): StoredEntryRefresher
    {
        $refresher = self::getContainer()->get(StoredEntryRefresher::class);
        self::assertInstanceOf(StoredEntryRefresher::class, $refresher);

        return $refresher;
    }
}
