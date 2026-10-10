<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\CommentsLoad;
use App\Service\Ingest\EntryIngestor;
use App\Service\Ingest\Pass\FeedIngestContext;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Tests\DbTestCase;

final class PlatformEntryRulesWiringTest extends DbTestCase
{
    public function testTheContainersIngestorAppliesTheTaggedRedditRule(): void
    {
        $ingestor = self::getContainer()->get(EntryIngestor::class);
        self::assertInstanceOf(EntryIngestor::class, $ingestor);
        $feed = new Feed('https://www.reddit.com/r/PHP/.rss');
        $this->entityManager->persist($feed);
        $this->entityManager->flush();
        $thread = new ParsedEntryModel(
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
            new ParsedFeedModel('Feed', null, null, null, [$thread]),
            new FeedIngestContext(new \DateTimeImmutable('2026-09-24T12:00:00Z'), null),
        );
        $this->entityManager->flush();

        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['feed' => $feed]);
        self::assertInstanceOf(Entry::class, $entry);
        self::assertNull($entry->getUrl());
        self::assertSame(CommentsLoad::Auto, $entry->getDiscussion()->commentsLoad);
    }

    public function testTheContainersIngestorLeadsAVideoPagesBodyWithItsPlayerLink(): void
    {
        $ingestor = self::getContainer()->get(EntryIngestor::class);
        self::assertInstanceOf(EntryIngestor::class, $ingestor);
        $feed = new Feed('https://www.youtube.com/feeds/videos.xml?channel_id=UCabc');
        $this->entityManager->persist($feed);
        $this->entityManager->flush();
        $video = new ParsedEntryModel(
            'yt:video:Xic3faS00Qs',
            'https://www.youtube.com/watch?v=Xic3faS00Qs',
            'Title',
            null,
            null,
            '<p>Description</p>',
            null,
        );

        $ingestor->ingest(
            $feed,
            new ParsedFeedModel('Feed', null, null, null, [$video]),
            new FeedIngestContext(new \DateTimeImmutable('2026-09-24T12:00:00Z'), null),
        );
        $this->entityManager->flush();

        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['feed' => $feed]);
        self::assertInstanceOf(Entry::class, $entry);
        $body = (string) $entry->getContentHtml();
        self::assertStringContainsString('href="https://www.youtube-nocookie.com/embed/Xic3faS00Qs"', $body);
        self::assertStringContainsString('<p>Description</p>', $body);
    }

    public function testTheContainersIngestorDropsABlueskyPlaceholder(): void
    {
        $ingestor = self::getContainer()->get(EntryIngestor::class);
        self::assertInstanceOf(EntryIngestor::class, $ingestor);
        $feed = new Feed('https://bsky.app/profile/bsky.app/rss');
        $this->entityManager->persist($feed);
        $this->entityManager->flush();
        $post = new ParsedEntryModel(
            'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.post/3mxhdhodv222n',
            'https://bsky.app/profile/bsky.app/post/3mxhdhodv222n',
            'a masterclass in alt text',
            null,
            null,
            '<p>a masterclass in alt text</p><p>[contains quote post or other embedded content]</p>',
            null,
            titleDerived: true,
        );

        $ingestor->ingest(
            $feed,
            new ParsedFeedModel('Feed', null, null, null, [$post]),
            new FeedIngestContext(new \DateTimeImmutable('2026-10-10T12:00:00Z'), null),
        );
        $this->entityManager->flush();

        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['feed' => $feed]);
        self::assertInstanceOf(Entry::class, $entry);
        self::assertSame('<p>a masterclass in alt text</p>', $entry->getContentHtml());
        self::assertSame('a masterclass in alt text', $entry->getSummary());
    }
}
