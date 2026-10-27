<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Entity\Feed;
use App\Entity\TranscriptParagraph;
use App\Enum\TranscriptFormat;
use App\Repository\EntryTranscriptRepository;
use App\Tests\DbTestCase;

final class EntryTranscriptRepositoryTest extends DbTestCase
{
    private Feed $feed;
    private EntryTranscriptRepository $transcripts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->feed = new Feed('https://pod.test/feed.xml');
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();
        $this->transcripts = self::getContainer()->get(EntryTranscriptRepository::class);
    }

    public function testClaimableRowsArePendingAndDueNewestEntryFirst(): void
    {
        $now = new \DateTimeImmutable('2026-10-27 12:00:00');
        [$older, $newer, $backedOff, $filled] = $this->seedTranscripts(4);
        $backedOff->retryAfter($now);
        $filled->fill([new TranscriptParagraph(0, null, 'x')], 'x');
        $this->entityManager->flush();

        self::assertSame(
            [$newer->requireId(), $older->requireId()],
            array_map(
                static fn (EntryTranscript $row): int => $row->requireId(),
                $this->transcripts->findClaimable($now, 10),
            ),
        );
    }

    public function testARowIsClaimedOnceUntilItsLeaseRunsOut(): void
    {
        $now = new \DateTimeImmutable('2026-10-27 12:00:00');
        [$row] = $this->seedTranscripts(1);

        self::assertCount(1, $this->transcripts->claim([$row], $now));
        self::assertSame([], $this->transcripts->claim([$row], $now));
        self::assertSame([], $this->transcripts->findClaimable($now, 10));
        self::assertCount(1, $this->transcripts->findClaimable($now->modify('+6 minutes'), 10));
    }

    public function testAFilledFailedOrNotYetDueRowIsNotClaimed(): void
    {
        $now = new \DateTimeImmutable('2026-10-27 12:00:00');
        [$filled, $failed, $notYetDue] = $this->seedTranscripts(3);
        $filled->fill([new TranscriptParagraph(0, null, 'x')], 'x');
        $failed->fail();
        $notYetDue->retryAfter($now);
        $this->entityManager->flush();

        self::assertSame([], $this->transcripts->claim([$filled, $failed, $notYetDue], $now));
    }

    public function testSearchTextsAreKeyedByEntryIdForFilledRowsOnly(): void
    {
        [$filled, $pending] = $this->seedTranscripts(2);
        $filled->fill([new TranscriptParagraph(0, null, 'Hallo')], 'Hallo');
        $this->entityManager->flush();
        $filledEntryId = $filled->getEntry()->requireId();

        self::assertSame(
            [$filledEntryId => 'Hallo'],
            $this->transcripts->searchTextsFor([$filledEntryId, $pending->getEntry()->requireId()]),
        );
    }

    public function testNoEntryIdsFindNoSearchTexts(): void
    {
        self::assertSame([], $this->transcripts->searchTextsFor([]));
    }

    public function testFindsTheTranscriptOfAnEntry(): void
    {
        [$row] = $this->seedTranscripts(1);

        self::assertSame($row, $this->transcripts->findForEntry($row->getEntry()));
    }

    public function testDeletingTheEntryDeletesItsTranscript(): void
    {
        [$row] = $this->seedTranscripts(1);
        $entryId = $row->getEntry()->requireId();
        $this->entityManager->clear();

        $this->entityManager->getConnection()->executeStatement('DELETE FROM entry WHERE id = ?', [$entryId]);

        self::assertSame(0, $this->transcripts->count([]));
    }

    /** @return list<EntryTranscript> */
    private function seedTranscripts(int $count): array
    {
        $rows = [];
        for ($index = 1; $index <= $count; $index++) {
            $entry = new Entry(
                $this->feed,
                'guid-' . $index,
                'https://pod.test/ep' . $index,
                'Episode ' . $index,
                new \DateTimeImmutable('2026-10-27 06:00:00'),
                new \DateTimeImmutable('2026-10-27 05:00:00'),
            );
            $this->entityManager->persist($entry);
            $row = new EntryTranscript($entry, 'https://pod.test/ep' . $index . '.vtt', TranscriptFormat::Vtt, null);
            $this->entityManager->persist($row);
            $rows[] = $row;
        }
        $this->entityManager->flush();

        return $rows;
    }
}
