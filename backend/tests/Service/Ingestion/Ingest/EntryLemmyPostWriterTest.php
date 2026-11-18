<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Ingest;

use App\Entity\DiscussionCounts;
use App\Entity\Entry;
use App\Entity\EntryLemmyPost;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Entity\PostCommunity;
use App\Service\Ingestion\Ingest\EntryIngestor;
use App\Service\Ingestion\Ingest\EntryLemmyPostWriter;
use App\Service\Ingestion\Ingest\Pass\FeedIngestContext;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;
use App\Tests\DbTestCase;
use App\Tests\Support\EntryIngestors;
use App\Tests\Support\QueryRecorder;
use Symfony\Component\Clock\MockClock;

final class EntryLemmyPostWriterTest extends DbTestCase
{
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

    public function testANewLemmyPostGetsARowWithItsCommunityAndScore(): void
    {
        $this->ingest(
            self::post()
                ->withDiscussionCounts(DiscussionCounts::of(7, 2))
                ->inCommunity(PostCommunity::of('technology@lemmy.world', 'https://lemmy.world/c/technology')),
        );

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame(7, $rows[0]->getScore());
        self::assertEquals(
            PostCommunity::of('technology@lemmy.world', 'https://lemmy.world/c/technology'),
            $rows[0]->getCommunity(),
        );
    }

    public function testAnEntryWithoutScoreOrCommunityGetsNoRow(): void
    {
        $this->ingest(self::post());

        self::assertSame([], $this->rows());
    }

    public function testACommunityOverTheColumnLengthIsNotStored(): void
    {
        $identity = str_repeat('a', EntryLemmyPost::IDENTITY_MAX_LENGTH + 1);
        $this->ingest(
            self::post()
                ->withDiscussionCounts(DiscussionCounts::of(1, null))
                ->inCommunity(PostCommunity::of($identity, 'https://x.test/c/a')),
        );

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]->getCommunity());
    }

    public function testACommunityExactlyAtTheColumnLengthsIsStored(): void
    {
        $identity = str_repeat('a', EntryLemmyPost::IDENTITY_MAX_LENGTH);
        $url = 'https://x.test/c/' . str_repeat('a', EntryLemmyPost::URL_MAX_LENGTH - 17);
        $this->ingest(self::post()->inCommunity(PostCommunity::of($identity, $url)));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame($identity, $rows[0]->getCommunity()?->identity);
        self::assertSame($url, $rows[0]->getCommunity()->url);
    }

    public function testAUrlOneOverTheColumnLengthIsNotStored(): void
    {
        $url = 'https://x.test/c/' . str_repeat('a', EntryLemmyPost::URL_MAX_LENGTH - 16);
        $this->ingest(
            self::post()
                ->withDiscussionCounts(DiscussionCounts::of(1, null))
                ->inCommunity(PostCommunity::of('a@x.test', $url)),
        );

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]->getCommunity());
    }

    public function testTheColumnLengthsCountCharactersNotBytes(): void
    {
        $identity = str_repeat('é', EntryLemmyPost::IDENTITY_MAX_LENGTH);
        $url = 'https://x.test/c/' . str_repeat('é', EntryLemmyPost::URL_MAX_LENGTH - 17);
        $this->ingest(self::post()->inCommunity(PostCommunity::of($identity, $url)));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame($identity, $rows[0]->getCommunity()?->identity);
        self::assertSame($url, $rows[0]->getCommunity()->url);
    }

    public function testAnOverlongCommunityAloneLeavesNoRow(): void
    {
        $url = 'https://x.test/c/' . str_repeat('a', EntryLemmyPost::URL_MAX_LENGTH);
        $this->ingest(self::post()->inCommunity(PostCommunity::of('a@x.test', $url)));

        self::assertSame([], $this->rows());
    }

    public function testRefreshingEntriesWithoutScoreOrCommunityAsksForNoRows(): void
    {
        $now = new \DateTimeImmutable('2026-11-18T12:00:00Z');
        $entry = new Entry($this->feed, 'plain', 'https://example.com/plain', 'Plain', $now, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();
        $writer = self::getContainer()->get(EntryLemmyPostWriter::class);
        self::assertInstanceOf(EntryLemmyPostWriter::class, $writer);

        $writer->refresh([[$entry, self::post()]]);

        self::assertSame([], $recorder->queriesMatching('entry_lemmy_post'));
    }

    public function testRefreshingAStoredPostUpdatesItsRowInPlace(): void
    {
        $this->ingest(self::post()->withDiscussionCounts(DiscussionCounts::of(7, 2)));
        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['feed' => $this->feed]);
        self::assertInstanceOf(Entry::class, $entry);
        $writer = self::getContainer()->get(EntryLemmyPostWriter::class);
        self::assertInstanceOf(EntryLemmyPostWriter::class, $writer);

        $writer->refresh([[$entry, self::post()->withDiscussionCounts(DiscussionCounts::of(9, 2))]]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame(9, $rows[0]->getScore());
    }

    private static function post(): ParsedEntryModel
    {
        return new ParsedEntryModel('g1', 'https://example.com/1', Headline::fromFeed('Post'), null, null, null, null);
    }

    /** @return list<EntryLemmyPost> */
    private function rows(): array
    {
        return $this->entityManager->getRepository(EntryLemmyPost::class)->findAll();
    }

    private function ingest(ParsedEntryModel $entry): void
    {
        $this->ingestor->ingest(
            $this->feed,
            new ParsedFeedModel('T', null, null, null, [$entry]),
            new FeedIngestContext(new \DateTimeImmutable('2026-11-18T12:00:00Z'), null),
        );
        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}
