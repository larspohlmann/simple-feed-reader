<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest\Platform;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\CommentsLoad;
use App\Service\Ingest\EntryIngestor;
use App\Service\Ingest\FeedIngestContext;
use App\Service\Parser\ParsedEntry;
use App\Service\Parser\ParsedFeed;
use App\Tests\DbTestCase;

final class PlatformEntryRulesWiringTest extends DbTestCase
{
    public function testTheContainersIngestorAppliesTheTaggedRedditRule(): void
    {
        $ingestor = self::getContainer()->get(EntryIngestor::class);
        self::assertInstanceOf(EntryIngestor::class, $ingestor);
        $feed = new Feed('https://www.reddit.com/r/PHP/.rss');
        $this->em->persist($feed);
        $this->em->flush();
        $thread = new ParsedEntry(
            't3_1abc',
            'https://www.reddit.com/r/PHP/comments/1abc/t/',
            'Title',
            null,
            null,
            '<p>body</p>',
            null,
        );

        $ingestor->ingest(
            $feed,
            new ParsedFeed('Feed', null, null, null, [$thread]),
            new FeedIngestContext(new \DateTimeImmutable('2026-09-24T12:00:00Z'), null),
        );
        $this->em->flush();

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['feed' => $feed]);
        self::assertInstanceOf(Entry::class, $entry);
        self::assertNull($entry->getUrl());
        self::assertSame(CommentsLoad::Auto, $entry->getDiscussion()->commentsLoad);
    }
}
