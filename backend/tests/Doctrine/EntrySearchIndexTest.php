<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Entity\TranscriptParagraph;
use App\Enum\TranscriptFormat;
use App\Tests\DbTestCase;

/** The triggers, through the ORM's own writes: the index answers for what an entry and its transcript say now. */
final class EntrySearchIndexTest extends DbTestCase
{
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessSqlite();

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();
    }

    public function testANewEntryIsFoundWhateverTheCaseOrAccents(): void
    {
        $entry = $this->entry('Ein Café in Zürich', 'ÄRGER heute');

        self::assertSame([$entry->requireId()], $this->matching('"cafe"'));
        self::assertSame([$entry->requireId()], $this->matching('"zurich" AND "arger"'));
    }

    public function testAnEditedTitleReplacesTheOldOne(): void
    {
        $entry = $this->entry('Berlin', null);

        $entry->setHeadline(Headline::fromFeed('Paris'));
        $this->entityManager->flush();

        self::assertSame([], $this->matching('"berlin"'));
        self::assertSame([$entry->requireId()], $this->matching('"paris"'));
    }

    public function testATranscriptIsIndexedAlongsideItsEntryAndDroppedWithIt(): void
    {
        $entry = $this->entry('Episode one', null);
        $transcript = $this->transcribed($entry, 'Über den Fluss');

        self::assertSame([$entry->requireId()], $this->matching('"uber den" AND "episode"'));

        $this->entityManager->remove($transcript);
        $this->entityManager->flush();

        self::assertSame([], $this->matching('"fluss"'));
        self::assertSame([$entry->requireId()], $this->matching('"episode"'));
    }

    public function testARefilledTranscriptReplacesItsOldText(): void
    {
        $entry = $this->entry('Episode one', null);
        $transcript = $this->transcribed($entry, 'Zwiebelring');

        $transcript->fill([new TranscriptParagraph(null, null, 'Kartoffel')], 'Kartoffel');
        $this->entityManager->flush();

        self::assertSame([], $this->matching('"zwiebel"'));
        self::assertSame([$entry->requireId()], $this->matching('"kartoffel"'));
    }

    public function testADeletedEntryLeavesTheIndex(): void
    {
        $entry = $this->entry('Ein Café', null);
        $this->transcribed($entry, 'Zwiebelring');

        $this->entityManager->remove($entry);
        $this->entityManager->flush();

        self::assertSame([], $this->matching('"cafe"'));
        self::assertSame([], $this->matching('"zwiebel"'));
    }

    private function entry(string $title, ?string $summary): Entry
    {
        $entry = new Entry(
            $this->feed,
            'guid-' . $title,
            'https://example.com/' . rawurlencode($title),
            $title,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $entry->setSummary($summary);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function transcribed(Entry $entry, string $searchText): EntryTranscript
    {
        $transcript = new EntryTranscript($entry, 'https://example.com/t.vtt', TranscriptFormat::Vtt, null);
        $transcript->fill([new TranscriptParagraph(null, null, $searchText)], $searchText);
        $this->entityManager->persist($transcript);
        $this->entityManager->flush();

        return $transcript;
    }

    /** @return list<int> */
    private function matching(string $query): array
    {
        /** @var list<int> $ids */
        $ids = array_map(
            static fn (mixed $rowId): int => \is_numeric($rowId) ? (int) $rowId : 0,
            $this->entityManager->getConnection()->fetchFirstColumn(
                'SELECT rowid FROM entry_search WHERE entry_search MATCH ? ORDER BY rowid',
                [$query],
            ),
        );

        return $ids;
    }
}
