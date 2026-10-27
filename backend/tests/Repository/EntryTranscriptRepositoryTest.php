<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\EntryTranscript;
use App\Entity\TranscriptParagraph;
use App\Repository\EntryTranscriptRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\TranscriptFixtures;

final class EntryTranscriptRepositoryTest extends DbTestCase
{
    private TranscriptFixtures $fixtures;
    private EntryTranscriptRepository $transcripts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = new TranscriptFixtures($this->entityManager);
        $this->transcripts = self::getContainer()->get(EntryTranscriptRepository::class);
    }

    public function testClaimableRowsArePendingAndDueNewestEntryFirst(): void
    {
        $now = new \DateTimeImmutable('2026-10-27 12:00:00');
        [$older, $newer, $backedOff, $filled] = $this->fixtures->pendingTranscripts(4);
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
        [$row] = $this->fixtures->pendingTranscripts(1);

        self::assertCount(1, $this->transcripts->claim([$row], $now));
        self::assertSame([], $this->transcripts->claim([$row], $now));
        self::assertSame([], $this->transcripts->findClaimable($now, 10));
        self::assertCount(1, $this->transcripts->findClaimable($now->modify('+6 minutes'), 10));
    }

    public function testAFilledFailedOrNotYetDueRowIsNotClaimed(): void
    {
        $now = new \DateTimeImmutable('2026-10-27 12:00:00');
        [$filled, $failed, $notYetDue] = $this->fixtures->pendingTranscripts(3);
        $filled->fill([new TranscriptParagraph(0, null, 'x')], 'x');
        $failed->fail();
        $notYetDue->retryAfter($now);
        $this->entityManager->flush();

        self::assertSame([], $this->transcripts->claim([$filled, $failed, $notYetDue], $now));
    }

    public function testSearchTextsAreKeyedByEntryIdForFilledRowsOnly(): void
    {
        [$filled, $pending] = $this->fixtures->pendingTranscripts(2);
        $filled->fill([new TranscriptParagraph(0, null, 'Hallo')], 'Hallo');
        $this->entityManager->flush();
        $filledEntryId = $filled->getEntry()->requireId();

        self::assertSame(
            [$filledEntryId => 'Hallo'],
            $this->transcripts->searchTextsFor([$filledEntryId, $pending->getEntry()->requireId()]),
        );
    }

    public function testEveryFilledEntrysSearchTextIsReturned(): void
    {
        [$first, $second] = $this->fixtures->pendingTranscripts(2);
        $first->fill([new TranscriptParagraph(0, null, 'Eins')], 'Eins');
        $second->fill([new TranscriptParagraph(0, null, 'Zwei')], 'Zwei');
        $this->entityManager->flush();
        $firstId = $first->getEntry()->requireId();
        $secondId = $second->getEntry()->requireId();

        self::assertSame(
            [$firstId => 'Eins', $secondId => 'Zwei'],
            $this->transcripts->searchTextsFor([$firstId, $secondId]),
        );
    }

    public function testNoEntryIdsFindNoSearchTexts(): void
    {
        self::assertSame([], $this->transcripts->searchTextsFor([]));
    }

    public function testFindsTheTranscriptOfAnEntry(): void
    {
        [$row] = $this->fixtures->pendingTranscripts(1);

        self::assertSame($row, $this->transcripts->findForEntry($row->getEntry()));
    }

    public function testFindsOnlyTheTranscriptOfTheEntryAsked(): void
    {
        [$first, $second] = $this->fixtures->pendingTranscripts(2);

        self::assertSame($second, $this->transcripts->findForEntry($second->getEntry()));
        self::assertNotSame($first, $this->transcripts->findForEntry($second->getEntry()));
    }

    public function testDeletingTheEntryDeletesItsTranscript(): void
    {
        [$row] = $this->fixtures->pendingTranscripts(1);
        $entryId = $row->getEntry()->requireId();
        $this->entityManager->clear();

        $this->entityManager->getConnection()->executeStatement('DELETE FROM entry WHERE id = ?', [$entryId]);

        self::assertSame(0, $this->transcripts->count([]));
    }
}
