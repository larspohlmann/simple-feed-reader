<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Ingest;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Service\Ingestion\Ingest\EntryIngestor;
use App\Service\Ingestion\Ingest\Pass\FeedIngestContext;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;
use App\Tests\DbTestCase;

final class TrackingFreeIngestTest extends DbTestCase
{
    public function testATrackedItemIsStoredCleanAndSharesItsHashWithThePlainOne(): void
    {
        $plain = $this->ingest('https://example.com/one-feed', 'g-plain', 'https://example.com/a');
        $tracked = $this->ingest('https://example.com/other-feed', 'g-tracked', 'https://example.com/a?utm_source=rss');

        self::assertSame('https://example.com/a', $tracked->getUrl());
        self::assertSame($plain->getUrlHash(), $tracked->getUrlHash());
    }

    private function ingest(string $feedUrl, string $guid, string $url): Entry
    {
        $ingestor = self::getContainer()->get(EntryIngestor::class);
        self::assertInstanceOf(EntryIngestor::class, $ingestor);
        $feed = new Feed($feedUrl);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();
        $item = new ParsedEntryModel($guid, $url, Headline::fromFeed('Title'), null, null, '<p>body</p>', null);

        $ingestor->ingest(
            $feed,
            new ParsedFeedModel('Feed', null, null, null, [$item]),
            new FeedIngestContext(new \DateTimeImmutable('2026-11-15T12:00:00Z'), null),
        );
        $this->entityManager->flush();

        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['feed' => $feed]);
        self::assertInstanceOf(Entry::class, $entry);

        return $entry;
    }
}
