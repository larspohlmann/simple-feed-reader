<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Ingest;

use App\Entity\DiscussionCounts;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Repository\EntryRepository;
use App\Service\Ingestion\Ingest\EntryCategoryWriter;
use App\Service\Ingestion\Ingest\EntryImageWriter;
use App\Service\Ingestion\Ingest\EntryIngestor;
use App\Service\Ingestion\Ingest\EntryTranscriptWriter;
use App\Service\Ingestion\Ingest\Factory\IngestedEntryFactory;
use App\Service\Ingestion\Ingest\FeedTitle\FeedTitleInterface;
use App\Service\Ingestion\Ingest\Pass\FeedIngestContext;
use App\Service\Ingestion\Ingest\PlatformEntryRules;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;
use App\Service\Foundation\Url\EntryUrlHash;
use App\Tests\DbTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

final class RefreshDiscussionCountsTest extends DbTestCase
{
    private const string POST = 'https://lemmy.world/post/1';

    private function ingestor(): EntryIngestor
    {
        $ingestor = self::getContainer()->get(EntryIngestor::class);
        self::assertInstanceOf(EntryIngestor::class, $ingestor);

        return $ingestor;
    }

    private function feed(): Feed
    {
        $feed = new Feed('https://lemmy.world/feeds/c/politics.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

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
            new FeedIngestContext(new \DateTimeImmutable('2026-07-21T12:00:00Z'), null),
        );
        $this->entityManager->flush();
        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['guid.value' => self::POST]);
        self::assertNotNull($entry);
        self::assertEquals(DiscussionCounts::of(10, 1), $entry->getDiscussionCounts());

        return $entry;
    }

    public function testUpdatesTheCountsOfAStoredEntry(): void
    {
        $feed = $this->feed();
        $entry = $this->storedPost($feed);

        $this->ingestor()->refreshDiscussionCounts(
            $feed,
            self::lemmyPost('42 points | <a href="' . self::POST . '">9 comments</a>'),
        );
        $this->entityManager->flush();

        self::assertEquals(DiscussionCounts::of(42, 9), $entry->getDiscussionCounts());
    }

    public function testUnknownCountsKeepTheStoredOnes(): void
    {
        $feed = $this->feed();
        $entry = $this->storedPost($feed);

        $this->ingestor()->refreshDiscussionCounts($feed, self::lemmyPost('a header Lemmy no longer writes'));
        $this->entityManager->flush();

        self::assertEquals(DiscussionCounts::of(10, 1), $entry->getDiscussionCounts());
    }

    public function testFeedsWithoutCountsSkipTheLookup(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn($this->entityManager->getClassMetadata(Entry::class));
        $entityManager->expects($this->never())->method('createQueryBuilder');
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);
        $entries = new EntryRepository($registry);
        $ingestor = new EntryIngestor(
            $this->createStub(EntityManagerInterface::class),
            $entries,
            self::getContainer()->get(EntryUrlHash::class),
            self::getContainer()->get(EntryCategoryWriter::class),
            self::getContainer()->get(PlatformEntryRules::class),
            self::getContainer()->get(IngestedEntryFactory::class),
            self::getContainer()->get(EntryImageWriter::class),
            self::getContainer()->get(FeedTitleInterface::class),
            self::getContainer()->get(EntryTranscriptWriter::class),
        );
        $article = new ParsedEntryModel(
            'g1',
            'https://example.com/a',
            Headline::fromFeed('A'),
            'x',
            null,
            '<p>Body</p>',
            null,
        );
        $plain = new ParsedFeedModel('T', null, null, null, [$article]);

        $ingestor->refreshDiscussionCounts($this->feed(), $plain);
    }
}
