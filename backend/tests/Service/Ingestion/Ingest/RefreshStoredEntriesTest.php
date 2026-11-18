<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Ingest;

use App\Entity\Entry;
use App\Entity\EntryLemmyPost;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Service\Outbound\Image\Model\DeclaredImageModel;
use App\Service\Ingestion\Ingest\EntryIngestor;
use App\Service\Ingestion\Ingest\Pass\FeedIngestContext;
use App\Service\Ingestion\Ingest\StoredEntryRefresher;
use App\Service\Ingestion\Parser\Model\ParsedEntryMediaModel;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;
use App\Tests\DbTestCase;

final class RefreshStoredEntriesTest extends DbTestCase
{
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

    private static function fetchedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-21T12:00:00Z');
    }

    private static function context(): FeedIngestContext
    {
        return new FeedIngestContext(self::fetchedAt(), null);
    }

    private function feed(string $url = 'https://example.com/feed'): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    private const string POST = 'https://lemmy.world/post/1';

    private static function header(string $counts): string
    {
        return 'submitted by <a href="https://lemmy.world/u/someone">someone</a> to '
            . '<a href="https://lemmy.world/c/politics">politics</a><br>' . $counts;
    }

    private static function lemmyPost(string $counts): ParsedFeedModel
    {
        $post = new ParsedEntryModel(
            self::POST,
            self::POST,
            Headline::fromFeed('Post'),
            'x',
            null,
            self::header($counts),
            null,
        );

        return new ParsedFeedModel('T', null, null, null, [$post]);
    }

    private function storedPost(Feed $feed): Entry
    {
        $this->ingestor()->ingest(
            $feed,
            self::lemmyPost('10 points | <a href="' . self::POST . '">1 comments</a>'),
            self::context(),
        );
        $this->entityManager->flush();
        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['guid.value' => self::POST]);
        self::assertNotNull($entry);
        self::assertSame(1, $entry->getCommentCount());

        return $entry;
    }

    private function lemmyPostRow(Entry $entry): ?EntryLemmyPost
    {
        return $this->entityManager->getRepository(EntryLemmyPost::class)->findOneBy(['entry' => $entry]);
    }

    private function parsedEntry(string $guid, ?DeclaredImageModel $image): ParsedEntryModel
    {
        return new ParsedEntryModel(
            $guid,
            null,
            Headline::fromFeed($guid),
            null,
            null,
            null,
            null,
            new ParsedEntryMediaModel($image),
        );
    }

    public function testPopulatesAnEntryIngestedWithoutOne(): void
    {
        $feed = $this->feed();
        $withoutImage = new ParsedFeedModel('T', null, null, null, [$this->parsedEntry('g2', null)]);
        $this->ingestor()->ingest($feed, $withoutImage, self::context());
        $this->entityManager->flush();

        $withImage = new ParsedFeedModel('T', null, null, null, [
            $this->parsedEntry('g2', new DeclaredImageModel('https://i/2.jpg', 700, null)),
        ]);
        $this->refresher()->refreshStoredEntries($feed, $withImage);
        $this->entityManager->flush();

        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['guid.value' => 'g2']);
        self::assertNotNull($entry);
        self::assertSame('https://i/2.jpg', $entry->getImageUrl());
        self::assertSame(700, $entry->getImageWidth());
        self::assertNull($entry->getImageHeight());
    }

    public function testNeverOverwritesAnExistingImage(): void
    {
        $feed = $this->feed();
        $this->ingestor()->ingest($feed, new ParsedFeedModel('T', null, null, null, [
            $this->parsedEntry('g3', new DeclaredImageModel('https://i/original.jpg', 900, 600)),
        ]), self::context());
        $this->entityManager->flush();

        $this->refresher()->refreshStoredEntries($feed, new ParsedFeedModel('T', null, null, null, [
            $this->parsedEntry('g3', new DeclaredImageModel('https://i/replacement.jpg', 100, 100)),
        ]));
        $this->entityManager->flush();

        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['guid.value' => 'g3']);
        self::assertNotNull($entry);
        self::assertSame('https://i/original.jpg', $entry->getImageUrl());
    }

    public function testIgnoresFeedItemsWithNoMatchingEntry(): void
    {
        $feed = $this->feed();

        $this->refresher()->refreshStoredEntries($feed, new ParsedFeedModel('T', null, null, null, [
            $this->parsedEntry('missing', new DeclaredImageModel('https://i/4.jpg', 400, 300)),
        ]));

        self::assertNull($this->entityManager->getRepository(Entry::class)->findOneBy(['guid.value' => 'missing']));
    }

    public function testAnUnknownEntryDoesNotStopTheRefreshOfTheOnesAfterIt(): void
    {
        $feed = $this->feed();
        $entry = $this->storedPost($feed);
        $unknown = $this->parsedEntry('missing', null);
        $known = self::lemmyPost('3 points | <a href="' . self::POST . '">1 comments</a>')->entries[0];

        $this->refresher()->refreshStoredEntries($feed, new ParsedFeedModel('T', null, null, null, [$unknown, $known]));
        $this->entityManager->flush();

        self::assertSame(3, $this->lemmyPostRow($entry)?->getScore());
    }

    public function testSkipsParsedEntriesThatCarryNoImage(): void
    {
        $feed = $this->feed();
        $parsedFeed = new ParsedFeedModel('T', null, null, null, [$this->parsedEntry('g5', null)]);
        $this->ingestor()->ingest($feed, $parsedFeed, self::context());
        $this->entityManager->flush();

        $this->refresher()->refreshStoredEntries($feed, new ParsedFeedModel('T', null, null, null, [
            $this->parsedEntry('g5', null),
        ]));

        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['guid.value' => 'g5']);
        self::assertNotNull($entry);
        self::assertNull($entry->getImageUrl());
    }

    public function testAnOverlongReplacementImageUrlIsNotFilledIn(): void
    {
        $feed = $this->feed();
        $parsedFeed = new ParsedFeedModel('T', null, null, null, [$this->parsedEntry('g6', null)]);
        $this->ingestor()->ingest($feed, $parsedFeed, self::context());
        $this->entityManager->flush();

        $overlongUrl = 'https://i/' . str_repeat('u', 2048) . '.jpg';
        $this->refresher()->refreshStoredEntries($feed, new ParsedFeedModel('T', null, null, null, [
            $this->parsedEntry('g6', new DeclaredImageModel($overlongUrl, 100, 100)),
        ]));

        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['guid.value' => 'g6']);
        self::assertNotNull($entry);
        self::assertNull($entry->getImageUrl());
    }

    public function testNeverRestoresADroppedImage(): void
    {
        $feed = $this->feed();
        $withImage = new ParsedFeedModel('T', null, null, null, [
            $this->parsedEntry('g-dropped', new DeclaredImageModel('https://i/pixel.gif')),
        ]);
        $this->ingestor()->ingest($feed, $withImage, self::context());
        $this->entityManager->flush();

        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['guid.value' => 'g-dropped']);
        self::assertNotNull($entry);
        $entry->getImage()->drop(new \DateTimeImmutable('2026-09-21 12:00:00'));
        $this->entityManager->flush();

        $this->refresher()->refreshStoredEntries($feed, $withImage);
        $this->entityManager->flush();

        self::assertNull($entry->getImageUrl());
    }

    public function testAnHttpReplacementImageUrlIsUpgradedAndFilledIn(): void
    {
        $feed = $this->feed();
        $parsedFeed = new ParsedFeedModel('T', null, null, null, [$this->parsedEntry('g7', null)]);
        $this->ingestor()->ingest($feed, $parsedFeed, self::context());
        $this->entityManager->flush();

        $this->refresher()->refreshStoredEntries($feed, new ParsedFeedModel('T', null, null, null, [
            $this->parsedEntry('g7', new DeclaredImageModel('http://i/7.jpg', 100, 100)),
        ]));

        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['guid.value' => 'g7']);
        self::assertNotNull($entry);
        self::assertSame('https://i/7.jpg', $entry->getImageUrl());
    }

    public function testUpdatesTheCountsOfAStoredEntry(): void
    {
        $feed = $this->feed();
        $entry = $this->storedPost($feed);

        $this->refresher()->refreshStoredEntries(
            $feed,
            self::lemmyPost('42 points | <a href="' . self::POST . '">9 comments</a>'),
        );
        $this->entityManager->flush();

        self::assertSame(9, $entry->getCommentCount());
    }

    public function testAnUnknownCommentCountKeepsTheStoredOne(): void
    {
        $feed = $this->feed();
        $entry = $this->storedPost($feed);

        $this->refresher()->refreshStoredEntries($feed, self::lemmyPost('a header Lemmy no longer writes'));
        $this->entityManager->flush();

        self::assertSame(1, $entry->getCommentCount());
    }

    public function testARefreshUpdatesTheStoredScore(): void
    {
        $feed = $this->feed();
        $entry = $this->storedPost($feed);
        self::assertSame(10, $this->lemmyPostRow($entry)?->getScore());

        $this->refresher()->refreshStoredEntries(
            $feed,
            self::lemmyPost('9 points | <a href="' . self::POST . '">2 comments</a>'),
        );
        $this->entityManager->flush();
        $this->entityManager->clear();

        $rows = $this->entityManager->getRepository(EntryLemmyPost::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame(9, $rows[0]->getScore());
        self::assertSame('politics@lemmy.world', $rows[0]->getCommunity()?->identity);
    }

    public function testARefreshCreatesTheRowAStoredLemmyPostLacks(): void
    {
        $feed = $this->feed();
        $entry = $this->storedPost($feed);
        $row = $this->lemmyPostRow($entry);
        self::assertNotNull($row);
        $this->entityManager->remove($row);
        $this->entityManager->flush();

        $this->refresher()->refreshStoredEntries(
            $feed,
            self::lemmyPost('4 points | <a href="' . self::POST . '">2 comments</a>'),
        );
        $this->entityManager->flush();

        $created = $this->lemmyPostRow($entry);
        self::assertSame(4, $created?->getScore());
        self::assertSame('politics@lemmy.world', $created->getCommunity()?->identity);
    }

    public function testAPostListedTwiceInOneParseGetsOneRow(): void
    {
        $feed = $this->feed();
        $entry = $this->storedPost($feed);
        $row = $this->lemmyPostRow($entry);
        self::assertNotNull($row);
        $this->entityManager->remove($row);
        $this->entityManager->flush();
        $once = self::lemmyPost('4 points | <a href="' . self::POST . '">2 comments</a>')->entries[0];

        $this->refresher()->refreshStoredEntries($feed, new ParsedFeedModel('T', null, null, null, [$once, $once]));
        $this->entityManager->flush();

        self::assertCount(1, $this->entityManager->getRepository(EntryLemmyPost::class)->findAll());
    }
}
