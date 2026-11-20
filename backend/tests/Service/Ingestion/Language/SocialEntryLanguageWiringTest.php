<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Language;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Enum\FeedLanguage;
use App\Enum\SourceFormat;
use App\Service\Ingestion\Ingest\EntryIngestor;
use App\Service\Ingestion\Ingest\Pass\FeedIngestContext;
use App\Service\Ingestion\Language\Factory\LanguageDetectionFactory;
use App\Service\Ingestion\Language\LanguageGuess;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;
use App\Tests\DbTestCase;

final class SocialEntryLanguageWiringTest extends DbTestCase
{
    public function testTheContainersIngestorStoresAShortEnglishPostTheDetectorReadsAsInterlinguaAsEnglish(): void
    {
        $detector = new LanguageGuess(new LanguageDetectionFactory()->create());
        self::assertNull($detector->detectLanguage("…\nPalantir exec nominated to lead CISA"));
        $ingestor = self::getContainer()->get(EntryIngestor::class);
        self::assertInstanceOf(EntryIngestor::class, $ingestor);
        $feed = new Feed('https://bsky.app/profile/news.example');
        $feed->setSourceFormat(SourceFormat::BLUESKY_API);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();
        $post = new ParsedEntryModel(
            'at://did:plc:example/app.bsky.feed.post/1',
            'https://bsky.app/profile/news.example/post/1',
            Headline::derived('…'),
            null,
            'Palantir exec nominated to lead CISA',
            '<p>Palantir exec nominated to lead CISA</p>',
            null,
        );

        $ingestor->ingest(
            $feed,
            new ParsedFeedModel('Feed', null, null, null, [$post]),
            new FeedIngestContext(new \DateTimeImmutable('2026-11-15T12:00:00Z'), null),
        );
        $this->entityManager->flush();

        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['feed' => $feed]);
        self::assertInstanceOf(Entry::class, $entry);
        self::assertSame(FeedLanguage::English, $entry->getText()->getLanguage());
    }
}
