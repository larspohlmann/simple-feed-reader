<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Service\Ingest\EntryIngestor;
use App\Service\Ingest\FeedIngestContext;
use App\Service\Parser\ParsedEntry;
use App\Service\Parser\ParsedFeed;
use App\Service\Parser\ParsedImage;
use App\Tests\DbTestCase;

final class FillMissingImagesTest extends DbTestCase
{
    private function ingestor(): EntryIngestor
    {
        $ingestor = self::getContainer()->get(EntryIngestor::class);
        self::assertInstanceOf(EntryIngestor::class, $ingestor);

        return $ingestor;
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
        $this->em->persist($feed);
        $this->em->flush();

        return $feed;
    }

    private function parsedEntry(string $guid, ?ParsedImage $image): ParsedEntry
    {
        return new ParsedEntry($guid, null, $guid, null, null, null, null, $image);
    }

    public function testPopulatesAnEntryIngestedWithoutOne(): void
    {
        $feed = $this->feed();
        $withoutImage = new ParsedFeed('T', null, null, [$this->parsedEntry('g2', null)]);
        $this->ingestor()->ingest($feed, $withoutImage, self::context());
        $this->em->flush();

        $withImage = new ParsedFeed('T', null, null, [
            $this->parsedEntry('g2', new ParsedImage('https://i/2.jpg', 700, null)),
        ]);
        $filled = $this->ingestor()->fillMissingImages($feed, $withImage);
        $this->em->flush();

        self::assertSame(1, $filled);
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'g2']);
        self::assertNotNull($entry);
        self::assertSame('https://i/2.jpg', $entry->getImageUrl());
        self::assertSame(700, $entry->getImageWidth());
        self::assertNull($entry->getImageHeight());
    }

    public function testNeverOverwritesAnExistingImage(): void
    {
        $feed = $this->feed();
        $this->ingestor()->ingest($feed, new ParsedFeed('T', null, null, [
            $this->parsedEntry('g3', new ParsedImage('https://i/original.jpg', 900, 600)),
        ]), self::context());
        $this->em->flush();

        $filled = $this->ingestor()->fillMissingImages($feed, new ParsedFeed('T', null, null, [
            $this->parsedEntry('g3', new ParsedImage('https://i/replacement.jpg', 100, 100)),
        ]));
        $this->em->flush();

        self::assertSame(0, $filled);
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'g3']);
        self::assertNotNull($entry);
        self::assertSame('https://i/original.jpg', $entry->getImageUrl());
    }

    public function testIgnoresFeedItemsWithNoMatchingEntry(): void
    {
        $feed = $this->feed();

        $filled = $this->ingestor()->fillMissingImages($feed, new ParsedFeed('T', null, null, [
            $this->parsedEntry('missing', new ParsedImage('https://i/4.jpg', 400, 300)),
        ]));

        self::assertSame(0, $filled);
    }

    public function testSkipsParsedEntriesThatCarryNoImage(): void
    {
        $feed = $this->feed();
        $g5 = new ParsedFeed('T', null, null, [$this->parsedEntry('g5', null)]);
        $this->ingestor()->ingest($feed, $g5, self::context());
        $this->em->flush();

        $filled = $this->ingestor()->fillMissingImages($feed, new ParsedFeed('T', null, null, [
            $this->parsedEntry('g5', null),
        ]));

        self::assertSame(0, $filled);
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'g5']);
        self::assertNotNull($entry);
        self::assertNull($entry->getImageUrl());
    }

    public function testAnOverlongReplacementImageUrlIsNotFilledIn(): void
    {
        $feed = $this->feed();
        $g6 = new ParsedFeed('T', null, null, [$this->parsedEntry('g6', null)]);
        $this->ingestor()->ingest($feed, $g6, self::context());
        $this->em->flush();

        $overlongUrl = 'https://i/' . str_repeat('u', 2048) . '.jpg';
        $filled = $this->ingestor()->fillMissingImages($feed, new ParsedFeed('T', null, null, [
            $this->parsedEntry('g6', new ParsedImage($overlongUrl, 100, 100)),
        ]));

        self::assertSame(0, $filled);
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'g6']);
        self::assertNotNull($entry);
        self::assertNull($entry->getImageUrl());
    }

    public function testAnHttpReplacementImageUrlIsNotFilledIn(): void
    {
        $feed = $this->feed();
        $g7 = new ParsedFeed('T', null, null, [$this->parsedEntry('g7', null)]);
        $this->ingestor()->ingest($feed, $g7, self::context());
        $this->em->flush();

        $filled = $this->ingestor()->fillMissingImages($feed, new ParsedFeed('T', null, null, [
            $this->parsedEntry('g7', new ParsedImage('http://i/7.jpg', 100, 100)),
        ]));

        self::assertSame(0, $filled);
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guid' => 'g7']);
        self::assertNotNull($entry);
        self::assertNull($entry->getImageUrl());
    }
}
