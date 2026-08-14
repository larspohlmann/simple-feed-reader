<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Service\Discovery\DiscoveredFeed;
use App\Service\EntryIngestor;
use App\Service\EntrySanitizer;
use App\Service\FeedScheduler;
use App\Service\Parser\ParsedEntry;
use App\Service\Parser\ParsedFeed;
use App\Service\Subscription\FirstFetchRecorder;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

final class FirstFetchRecorderTest extends DbTestCase
{
    private FirstFetchRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $clock = new MockClock('2026-06-01T00:00:00Z');
        $this->recorder = new FirstFetchRecorder(
            new EntryIngestor(
                $this->em,
                $this->em->getRepository(Entry::class),
                new EntrySanitizer(),
            ),
            new FeedScheduler($clock),
            $this->em,
            $clock,
        );
    }

    public function testAFirstFetchStoresTheArticlesOwnPublicationDates(): void
    {
        $feed = $this->feed();
        $discovered = $this->discovered($feed, [
            $this->parsedEntry('a', new \DateTimeImmutable('2020-03-01 00:00:00')),
        ]);

        $this->recorder->record($feed, $discovered);

        self::assertSame('2020-03-01 00:00:00', $this->effectiveDateOf($feed, 'a'));
    }

    public function testAFirstFetchStoresAtMostTwoHundredEntries(): void
    {
        $feed = $this->feed();

        self::assertSame(200, $this->recorder->record($feed, $this->discovered($feed, $this->parsedEntries(250))));
    }

    public function testTheFirstFetchKeepsTheNewestEntries(): void
    {
        $feed = $this->feed();

        $this->recorder->record($feed, $this->discovered($feed, $this->parsedEntries(250)));

        self::assertNull($this->findByGuid($feed, 'guid-0'));
        self::assertNotNull($this->findByGuid($feed, 'guid-249'));
    }

    private function feed(): Feed
    {
        $feed = new Feed('https://example.com/feed.xml');
        $this->em->persist($feed);
        $this->em->flush();

        return $feed;
    }

    /** @param list<ParsedEntry> $entries */
    private function discovered(Feed $feed, array $entries): DiscoveredFeed
    {
        return new DiscoveredFeed(
            $feed->getUrl(),
            new ParsedFeed('Discovered', null, null, $entries),
        );
    }

    private function parsedEntry(string $guid, ?\DateTimeImmutable $publishedAt): ParsedEntry
    {
        return new ParsedEntry(
            guid: $guid,
            url: 'https://example.com/' . $guid,
            title: 'Title ' . $guid,
            author: null,
            summary: null,
            contentHtml: '<p>Body.</p>',
            publishedAt: $publishedAt,
            image: null,
        );
    }

    /** @return list<ParsedEntry> */
    private function parsedEntries(int $count): array
    {
        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $entries[] = $this->parsedEntry(
                'guid-' . $i,
                new \DateTimeImmutable(sprintf('2026-01-01 00:00:00 +%d minutes', $i)),
            );
        }

        return $entries;
    }

    private function effectiveDateOf(Feed $feed, string $guid): string
    {
        $entry = $this->findByGuid($feed, $guid);
        self::assertNotNull($entry);

        return $entry->getEffectiveDate()->format('Y-m-d H:i:s');
    }

    private function findByGuid(Feed $feed, string $guid): ?Entry
    {
        /** @var Entry|null $entry */
        $entry = $this->em->getRepository(Entry::class)->findOneBy([
            'feed' => $feed,
            'guidHash' => hash('sha256', $guid),
        ]);

        return $entry;
    }
}
