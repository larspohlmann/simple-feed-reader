<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Service\EntryIngestor;
use App\Service\Parser\ParsedEntry;
use App\Service\Parser\ParsedFeed;
use App\Tests\DbTestCase;

final class CorrectPublishedDatesTest extends DbTestCase
{
    private function ingestor(): EntryIngestor
    {
        $ingestor = self::getContainer()->get(EntryIngestor::class);
        self::assertInstanceOf(EntryIngestor::class, $ingestor);

        return $ingestor;
    }

    private function parsedEntry(string $guid, ?\DateTimeImmutable $published): ParsedEntry
    {
        return new ParsedEntry($guid, null, $guid, null, null, null, $published);
    }

    public function testRewritesTheStoredDateWhenTheReparsedValueDiffers(): void
    {
        $feed = new Feed('https://example.com/f.xml');
        $this->em->persist($feed);
        // Skewed as if stored before the UTC fix (2h ahead of the true time).
        $entry = new Entry($feed, 'a', null, 'a', new \DateTimeImmutable('2026-07-24T15:00:00Z'));
        $entry->setPublishedAt(new \DateTimeImmutable('2026-07-24T17:51:45Z'));
        $this->em->persist($entry);
        $this->em->flush();

        $correct = new \DateTimeImmutable('2026-07-24T15:51:45Z');
        $updated = $this->ingestor()->correctPublishedDates(
            $feed,
            new ParsedFeed(null, null, null, [$this->parsedEntry('a', $correct)]),
        );
        $this->em->flush();

        self::assertSame(1, $updated);
        $this->em->refresh($entry);
        self::assertSame('2026-07-24T15:51:45+00:00', $entry->getPublishedAt()?->format(\DateTimeInterface::ATOM));
    }

    public function testLeavesAlreadyCorrectDatesUntouched(): void
    {
        $feed = new Feed('https://example.com/g.xml');
        $this->em->persist($feed);
        $good = new \DateTimeImmutable('2026-07-24T15:51:45Z');
        $entry = new Entry($feed, 'b', null, 'b', new \DateTimeImmutable('2026-07-24T16:00:00Z'));
        $entry->setPublishedAt($good);
        $this->em->persist($entry);
        $this->em->flush();

        $updated = $this->ingestor()->correctPublishedDates(
            $feed,
            new ParsedFeed(null, null, null, [$this->parsedEntry('b', $good)]),
        );

        self::assertSame(0, $updated);
    }

    public function testIgnoresFeedItemsWithNoMatchingEntry(): void
    {
        $feed = new Feed('https://example.com/h.xml');
        $this->em->persist($feed);
        $this->em->flush();

        $updated = $this->ingestor()->correctPublishedDates(
            $feed,
            new ParsedFeed(null, null, null, [
                $this->parsedEntry('missing', new \DateTimeImmutable('2026-07-24T15:00:00Z')),
            ]),
        );

        self::assertSame(0, $updated);
    }
}
