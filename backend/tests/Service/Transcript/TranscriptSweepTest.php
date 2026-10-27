<?php

declare(strict_types=1);

namespace App\Tests\Service\Transcript;

use App\Entity\EntryTranscript;
use App\Service\Transcript\Model\TranscriptBudgetModel;
use App\Service\Transcript\Model\TranscriptSweepReportModel;
use App\Service\Transcript\TranscriptSource\TranscriptSourceInterface;
use App\Service\Transcript\TranscriptSweep;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\ScriptedTranscriptSource;
use App\Tests\Support\TranscriptFixtures;
use App\Tests\Support\TranscriptSearchFixtures;

final class TranscriptSweepTest extends DbTestCase
{
    private RecordingSearchIndexWriter $indexWriter;
    private TranscriptFixtures $fixtures;
    private TranscriptSearchFixtures $searchFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new TranscriptFixtures($this->entityManager);
        $this->searchFixtures = new TranscriptSearchFixtures($this->entityManager);
        $this->indexWriter = TranscriptSearchFixtures::installDatabaseMatching(self::getContainer());
    }

    public function testFillsTheNewestDueRowsWithinTheBudgetAndReindexesThem(): void
    {
        [$older, $newer] = $this->fixtures->pendingTranscripts(2);
        $this->useSource(ScriptedTranscriptSource::answering(TranscriptFixtures::READABLE));

        $report = $this->sweep()->sweep(TranscriptBudgetModel::fetches(1));

        self::assertSame(1, $report->claimed);
        self::assertSame(1, $report->filled);
        self::assertSame('filled', $this->statusOf($newer));
        self::assertSame('pending', $this->statusOf($older));
        self::assertSame([$newer->getEntry()->requireId()], $this->upsertedIds());
    }

    public function testAFilledEpisodeGainsTheSavedSearchWhoseTermIsOnlyInItsTranscript(): void
    {
        [$row] = $this->fixtures->pendingTranscripts(1);
        $search = $this->searchFixtures->searchForTranscriptOnlyTerm();
        $this->useSource(ScriptedTranscriptSource::answering(TranscriptFixtures::READABLE));

        $this->sweep()->sweep(TranscriptBudgetModel::fetches(1));
        self::assertSame([$row->getEntry()->requireId()], $this->searchFixtures->memberEntryIds($search));

        $this->sweep()->sweep(TranscriptBudgetModel::fetches(1));
        self::assertSame([$row->getEntry()->requireId()], $this->searchFixtures->memberEntryIds($search));
    }

    public function testAFailedFetchIsFlushedButNotReindexed(): void
    {
        [$row] = $this->fixtures->pendingTranscripts(1);
        $this->useSource(ScriptedTranscriptSource::refusing());

        $report = $this->sweep()->sweep(TranscriptBudgetModel::fetches(5));

        self::assertSame(1, $report->claimed);
        self::assertSame(0, $report->filled);
        self::assertSame('failed', $this->statusOf($row));
        self::assertSame([], $this->upsertedIds());
    }

    public function testAnUnexpectedFailureLeavesTheOtherRowsFilled(): void
    {
        [$older, $newer] = $this->fixtures->pendingTranscripts(2);
        $this->useSource(
            ScriptedTranscriptSource::answering(TranscriptFixtures::READABLE)
                ->failingAt($newer->getUrl(), new \RuntimeException('boom')),
        );

        $report = $this->sweep()->sweep(TranscriptBudgetModel::fetches(2));

        self::assertSame(2, $report->claimed);
        self::assertSame(1, $report->filled);
        self::assertSame('filled', $this->statusOf($older));
        self::assertSame('pending', $this->statusOf($newer));
        self::assertSame(
            [$older->getEntry()->requireId()],
            array_map(static fn ($indexed): int => $indexed->id, $this->indexWriter->upserts[0]),
        );
    }

    public function testAnEmptyBudgetTouchesNothing(): void
    {
        [$row] = $this->fixtures->pendingTranscripts(1);

        $report = $this->sweep()->sweep(TranscriptBudgetModel::fetches(0));

        self::assertEquals(TranscriptSweepReportModel::none(), $report);
        self::assertSame('pending', $this->statusOf($row));
    }

    public function testTheReportLogsItsCounts(): void
    {
        self::assertSame(['claimed' => 3, 'filled' => 2], (new TranscriptSweepReportModel(3, 2))->toLogContext());
    }

    private function sweep(): TranscriptSweep
    {
        $sweep = self::getContainer()->get(TranscriptSweep::class);
        self::assertInstanceOf(TranscriptSweep::class, $sweep);

        return $sweep;
    }

    private function useSource(TranscriptSourceInterface $source): void
    {
        self::getContainer()->set(TranscriptSourceInterface::class, $source);
    }

    /** @return list<int> */
    private function upsertedIds(): array
    {
        return array_map(static fn ($indexed): int => $indexed->id, $this->indexWriter->upserted());
    }

    private function statusOf(EntryTranscript $row): string
    {
        $status = $this->entityManager->getConnection()
            ->fetchOne('SELECT status FROM entry_transcript WHERE id = ?', [$row->requireId()]);
        self::assertIsString($status);

        return $status;
    }
}
