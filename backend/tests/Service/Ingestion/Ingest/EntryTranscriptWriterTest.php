<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest;

use App\Entity\EntryTranscript;
use App\Entity\Feed;
use App\Enum\TranscriptFormat;
use App\Service\Ingest\EntryIngestor;
use App\Service\Ingest\Pass\FeedIngestContext;
use App\Tests\DbTestCase;
use App\Tests\Support\EntryIngestors;
use App\Tests\Support\FeedFormatParsers;
use Symfony\Component\Clock\MockClock;

final class EntryTranscriptWriterTest extends DbTestCase
{
    private EntryIngestor $ingestor;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ingestor = EntryIngestors::build($this->entityManager, new MockClock('2026-09-21 12:00:00'));

        $this->feed = new Feed('https://example.com/feed');
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();
    }

    public function testOnlyTheEpisodeThatDeclaresATranscriptGetsAPendingRow(): void
    {
        $this->ingest([
            ['with', '<podcast:transcript url="https://pod.test/ep.vtt" type="text/vtt" language="de"/>'],
            ['without', ''],
        ]);

        $rows = $this->entityManager->getRepository(EntryTranscript::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame('https://pod.test/ep.vtt', $rows[0]->getUrl());
        self::assertTrue($rows[0]->isPending());
        self::assertSame(TranscriptFormat::Vtt, $rows[0]->getFormat());
        self::assertSame('de', $rows[0]->getLanguage());
        self::assertSame('with', $rows[0]->getEntry()->getGuid());
    }

    public function testAUrlThatIsNotAbsoluteHttpQueuesNothing(): void
    {
        $this->ingest([
            ['script', '<podcast:transcript url="javascript:alert(1)" type="text/vtt"/>'],
            ['relative', '<podcast:transcript url="/ep.vtt" type="text/vtt"/>'],
        ]);

        self::assertSame([], $this->entityManager->getRepository(EntryTranscript::class)->findAll());
    }

    /** @param list<array{0: string, 1: string}> $items guid and the transcript markup of each item */
    private function ingest(array $items): void
    {
        $xml = '<?xml version="1.0"?><rss version="2.0" xmlns:podcast="https://podcastindex.org/namespace/1.0">'
            . '<channel><title>Pod</title>';
        foreach ($items as [$guid, $markup]) {
            $xml .= '<item><title>' . $guid . '</title><guid>' . $guid . '</guid>'
                . '<link>https://example.com/' . $guid . '</link>' . $markup . '</item>';
        }
        $xml .= '</channel></rss>';

        $this->ingestor->ingest(
            $this->feed,
            FeedFormatParsers::feed($xml),
            new FeedIngestContext(new \DateTimeImmutable('2026-07-21T12:00:00Z'), null),
        );
        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}
