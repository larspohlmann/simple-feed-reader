<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Ingest;

use App\Entity\Discussion;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Entity\LemmyPost;
use App\Entity\PostCommunity;
use App\Enum\CommentsLoad;
use App\Service\Ingestion\Ingest\CanonicalItemWriter;
use App\Service\Ingestion\Ingest\EntryIngestor;
use App\Service\Ingestion\Ingest\LemmyPostWriter;
use App\Service\Ingestion\Ingest\Pass\FeedIngestContext;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;
use App\Tests\DbTestCase;
use App\Tests\Support\EntryIngestors;
use App\Tests\Support\QueryRecorder;
use Symfony\Component\Clock\MockClock;

final class LemmyPostWriterTest extends DbTestCase
{
    private const string PAGE = 'https://lemmy.example/post/42';

    private EntryIngestor $ingestor;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ingestor = EntryIngestors::build($this->entityManager, new MockClock('2026-11-18 12:00:00'));

        $this->feed = new Feed('https://example.com/feed');
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();
    }

    public function testANewLemmyPostGetsARowWithItsCommunity(): void
    {
        $this->ingestEntry(
            self::buildParsedPost()
                ->inCommunity(
                    PostCommunity::fromIdentityAndUrl('technology@lemmy.world', 'https://lemmy.world/c/technology'),
                ),
        );

        $rows = $this->readRows();
        self::assertCount(1, $rows);
        self::assertEquals(
            PostCommunity::fromIdentityAndUrl('technology@lemmy.world', 'https://lemmy.world/c/technology'),
            $rows[0]->getCommunity(),
        );
    }

    public function testAnEntryWithoutACommunityGetsNoRow(): void
    {
        $this->ingestEntry(self::buildParsedPost());

        self::assertSame([], $this->readRows());
    }

    public function testACommunityOverTheColumnLengthIsNotStored(): void
    {
        $identity = str_repeat('a', LemmyPost::IDENTITY_MAX_LENGTH + 1);
        $this->ingestEntry(
            self::buildParsedPost()
                ->inCommunity(PostCommunity::fromIdentityAndUrl($identity, 'https://x.test/c/a')),
        );

        self::assertSame([], $this->readRows());
    }

    public function testACommunityExactlyAtTheColumnLengthsIsStored(): void
    {
        $identity = str_repeat('a', LemmyPost::IDENTITY_MAX_LENGTH);
        $url = 'https://x.test/c/' . str_repeat('a', LemmyPost::URL_MAX_LENGTH - 17);
        $this->ingestEntry(self::buildParsedPost()->inCommunity(PostCommunity::fromIdentityAndUrl($identity, $url)));

        $rows = $this->readRows();
        self::assertCount(1, $rows);
        self::assertSame($identity, $rows[0]->getCommunity()?->identity);
        self::assertSame($url, $rows[0]->getCommunity()->url);
    }

    public function testAUrlOneOverTheColumnLengthIsNotStored(): void
    {
        $url = 'https://x.test/c/' . str_repeat('a', LemmyPost::URL_MAX_LENGTH - 16);
        $this->ingestEntry(
            self::buildParsedPost()
                ->inCommunity(PostCommunity::fromIdentityAndUrl('a@x.test', $url)),
        );

        self::assertSame([], $this->readRows());
    }

    public function testTheColumnLengthsCountCharactersNotBytes(): void
    {
        $identity = str_repeat('é', LemmyPost::IDENTITY_MAX_LENGTH);
        $url = 'https://x.test/c/' . str_repeat('é', LemmyPost::URL_MAX_LENGTH - 17);
        $this->ingestEntry(self::buildParsedPost()->inCommunity(PostCommunity::fromIdentityAndUrl($identity, $url)));

        $rows = $this->readRows();
        self::assertCount(1, $rows);
        self::assertSame($identity, $rows[0]->getCommunity()?->identity);
        self::assertSame($url, $rows[0]->getCommunity()->url);
    }

    public function testAnOverlongCommunityAloneLeavesNoRow(): void
    {
        $url = 'https://x.test/c/' . str_repeat('a', LemmyPost::URL_MAX_LENGTH);
        $this->ingestEntry(self::buildParsedPost()->inCommunity(PostCommunity::fromIdentityAndUrl('a@x.test', $url)));

        self::assertSame([], $this->readRows());
    }

    public function testEntriesWithoutACommunityAskForNoRows(): void
    {
        $now = new \DateTimeImmutable('2026-11-18T12:00:00Z');
        $entry = new Entry($this->feed, 'plain', 'https://example.com/plain', 'Plain', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();
        $writer = self::getContainer()->get(LemmyPostWriter::class);
        self::assertInstanceOf(LemmyPostWriter::class, $writer);

        $writer->attach([[$entry, self::buildParsedPost()]]);

        self::assertSame([], $recorder->queriesMatching('lemmy_post'));
    }

    public function testCommunityStoredOncePerItem(): void
    {
        $community = PostCommunity::fromIdentityAndUrl('technology@lemmy.world', 'https://lemmy.world/c/technology');
        $otherFeed = new Feed('https://example.com/other');
        $this->entityManager->persist($otherFeed);
        $this->entityManager->flush();

        $this->ingestEntry(self::buildParsedPost()->inCommunity($community));
        $this->ingestEntry(self::buildParsedPost()->inCommunity($community), $otherFeed);

        $entries = $this->entityManager->getRepository(Entry::class)->findAll();
        self::assertCount(2, $entries);
        self::assertSame($entries[0]->getCanonicalItem()?->getId(), $entries[1]->getCanonicalItem()?->getId());
        $rows = $this->readRows();
        self::assertCount(1, $rows);
        self::assertEquals($community, $rows[0]->getCommunity());
    }

    public function testBothCopiesInOneBatchStoreOneRow(): void
    {
        $community = PostCommunity::fromIdentityAndUrl('technology@lemmy.world', 'https://lemmy.world/c/technology');
        $otherFeed = new Feed('https://example.com/other');
        $this->entityManager->persist($otherFeed);
        $this->entityManager->flush();
        $first = $this->persistCopy($this->feed, 'a');
        $second = $this->persistCopy($otherFeed, 'b');
        $pair = static fn (Entry $entry): array => [$entry, self::buildParsedPost()->inCommunity($community)];

        self::getContainer()->get(CanonicalItemWriter::class)->attach([$pair($first), $pair($second)]);
        self::getContainer()->get(LemmyPostWriter::class)->attach([$pair($first), $pair($second)]);
        $this->entityManager->flush();

        self::assertCount(1, $this->readRows());
    }

    public function testRefreshingAStoredPostMovesItsCommunityInPlace(): void
    {
        $this->ingestEntry(self::buildParsedPost()->inCommunity(
            PostCommunity::fromIdentityAndUrl('technology@lemmy.world', 'https://lemmy.world/c/technology'),
        ));
        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['feed' => $this->feed]);
        self::assertInstanceOf(Entry::class, $entry);
        $writer = self::getContainer()->get(LemmyPostWriter::class);
        self::assertInstanceOf(LemmyPostWriter::class, $writer);

        $moved = PostCommunity::fromIdentityAndUrl('news@lemmy.world', 'https://lemmy.world/c/news');
        $writer->attach([[$entry, self::buildParsedPost()->inCommunity($moved)]]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $rows = $this->readRows();
        self::assertCount(1, $rows);
        self::assertEquals($moved, $rows[0]->getCommunity());
    }

    public function testTheLastCopyOfABatchDecidesTheCommunity(): void
    {
        $otherFeed = new Feed('https://example.com/other');
        $this->entityManager->persist($otherFeed);
        $this->entityManager->flush();
        $first = $this->persistCopy($this->feed, 'a');
        $second = $this->persistCopy($otherFeed, 'b');
        $technology = PostCommunity::fromIdentityAndUrl('technology@lemmy.world', 'https://lemmy.world/c/technology');
        $news = PostCommunity::fromIdentityAndUrl('news@lemmy.world', 'https://lemmy.world/c/news');
        $pairs = [
            [$first, self::buildParsedPost()->inCommunity($technology)],
            [$second, self::buildParsedPost()->inCommunity($news)],
        ];

        self::getContainer()->get(CanonicalItemWriter::class)->attach($pairs);
        self::getContainer()->get(LemmyPostWriter::class)->attach($pairs);
        $this->entityManager->flush();

        $rows = $this->readRows();
        self::assertCount(1, $rows);
        self::assertEquals($news, $rows[0]->getCommunity());
    }

    public function testAStoredPostWithTheSameCommunityIsNotWrittenAgain(): void
    {
        $community = PostCommunity::fromIdentityAndUrl('technology@lemmy.world', 'https://lemmy.world/c/technology');
        $this->ingestEntry(self::buildParsedPost()->inCommunity($community));
        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['feed' => $this->feed]);
        self::assertInstanceOf(Entry::class, $entry);
        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();
        $writer = self::getContainer()->get(LemmyPostWriter::class);
        self::assertInstanceOf(LemmyPostWriter::class, $writer);

        $writer->attach([[$entry, self::buildParsedPost()->inCommunity($community)]]);

        self::assertSame([], $recorder->queriesMatching('INSERT INTO lemmy_post'));
    }

    public function testAnEntryWithoutAnItemStoresNothing(): void
    {
        $entry = $this->persistCopy($this->feed, 'unlinked');
        $writer = self::getContainer()->get(LemmyPostWriter::class);
        self::assertInstanceOf(LemmyPostWriter::class, $writer);
        $community = PostCommunity::fromIdentityAndUrl('technology@lemmy.world', 'https://lemmy.world/c/technology');

        $writer->attach([[$entry, self::buildParsedPost()->inCommunity($community)]]);
        $this->entityManager->flush();

        self::assertNull($entry->getCanonicalItem());
        self::assertSame([], $this->readRows());
    }

    private function persistCopy(Feed $feed, string $guid): Entry
    {
        $now = new \DateTimeImmutable('2026-11-18T12:00:00Z');
        $entry = new Entry($feed, $guid, null, 'Post', $now, $now);
        $entry->setDiscussion(Discussion::fromPageAndFeed(self::PAGE, null, CommentsLoad::Auto));
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private static function buildParsedPost(): ParsedEntryModel
    {
        return new ParsedEntryModel(
            'g1',
            'https://example.com/1',
            Headline::fromFeed('Post'),
            null,
            null,
            null,
            null,
        )->asDiscussionThread(Discussion::fromPageAndFeed(self::PAGE, null, CommentsLoad::Auto));
    }

    /** @return list<LemmyPost> */
    private function readRows(): array
    {
        return $this->entityManager->getRepository(LemmyPost::class)->findAll();
    }

    private function ingestEntry(ParsedEntryModel $entry, ?Feed $feed = null): void
    {
        $this->ingestor->ingest(
            $this->entityManager->find(Feed::class, ($feed ?? $this->feed)->requireId()) ?? self::fail('feed'),
            new ParsedFeedModel('T', null, null, null, [$entry]),
            new FeedIngestContext(new \DateTimeImmutable('2026-11-18T12:00:00Z'), null),
        );
        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}
