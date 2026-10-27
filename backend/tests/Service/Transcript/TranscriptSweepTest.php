<?php

declare(strict_types=1);

namespace App\Tests\Service\Transcript;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\User;
use App\Enum\TranscriptFormat;
use App\Repository\DatabaseSavedSearchMatcher;
use App\Service\Search\Index\SearchIndexWriter\SearchIndexWriterInterface;
use App\Service\Search\Membership\SavedSearchMatcher\SavedSearchMatcherInterface;
use App\Service\Transcript\Model\TranscriptBudgetModel;
use App\Service\Transcript\Model\TranscriptSweepReportModel;
use App\Service\Transcript\TranscriptSource\TranscriptSourceInterface;
use App\Service\Transcript\TranscriptSweep;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\ScriptedTranscriptSource;

final class TranscriptSweepTest extends DbTestCase
{
    private const string READABLE = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHallo.\n";

    private RecordingSearchIndexWriter $indexWriter;

    protected function setUp(): void
    {
        parent::setUp();
        $databaseMatcher = self::getContainer()->get(DatabaseSavedSearchMatcher::class);
        self::getContainer()->set(SavedSearchMatcherInterface::class, $databaseMatcher);
        $this->indexWriter = new RecordingSearchIndexWriter();
        self::getContainer()->set(SearchIndexWriterInterface::class, $this->indexWriter);
    }

    public function testFillsTheNewestDueRowsWithinTheBudgetAndReindexesThem(): void
    {
        [$older, $newer] = $this->seedPendingTranscripts(2);
        $this->useSource(ScriptedTranscriptSource::answering(self::READABLE));

        $report = $this->sweep()->sweep(TranscriptBudgetModel::fetches(1));

        self::assertSame(1, $report->claimed);
        self::assertSame(1, $report->filled);
        self::assertSame('filled', $this->statusOf($newer));
        self::assertSame('pending', $this->statusOf($older));
        self::assertSame([$newer->getEntry()->requireId()], $this->upsertedIds());
    }

    public function testAFilledEpisodeGainsTheSavedSearchWhoseTermIsOnlyInItsTranscript(): void
    {
        [$row] = $this->seedPendingTranscripts(1);
        $search = $this->searchForTranscriptOnlyTerm();
        $this->useSource(ScriptedTranscriptSource::answering(self::READABLE));

        $this->sweep()->sweep(TranscriptBudgetModel::fetches(1));
        self::assertSame([$row->getEntry()->requireId()], $this->memberEntryIds($search));

        $this->sweep()->sweep(TranscriptBudgetModel::fetches(1));
        self::assertSame([$row->getEntry()->requireId()], $this->memberEntryIds($search));
    }

    public function testAFailedFetchIsFlushedButNotReindexed(): void
    {
        [$row] = $this->seedPendingTranscripts(1);
        $this->useSource(ScriptedTranscriptSource::refusing());

        $report = $this->sweep()->sweep(TranscriptBudgetModel::fetches(5));

        self::assertSame(1, $report->claimed);
        self::assertSame(0, $report->filled);
        self::assertSame('failed', $this->statusOf($row));
        self::assertSame([], $this->upsertedIds());
    }

    public function testAnUnexpectedFailureLeavesTheOtherRowsFilled(): void
    {
        [$older, $newer] = $this->seedPendingTranscripts(2);
        $this->useSource(
            ScriptedTranscriptSource::answering(self::READABLE)
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
        [$row] = $this->seedPendingTranscripts(1);

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

    /** @return list<EntryTranscript> */
    private function searchForTranscriptOnlyTerm(): SavedSearch
    {
        $user = new User('rematch@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->entityManager->persist($user);
        $search = new SavedSearch($user, 'Hallo', false);
        $this->entityManager->persist($search);
        $this->entityManager->flush();

        return $search;
    }

    /** @return list<int> */
    private function memberEntryIds(SavedSearch $search): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT entry_id FROM saved_search_entry WHERE saved_search_id = ?',
            [$search->getId()],
        );

        return array_map(intval(...), $ids);
    }

    private function seedPendingTranscripts(int $count): array
    {
        $feed = new Feed('https://pod.test/feed.xml');
        $this->entityManager->persist($feed);
        $rows = [];
        for ($index = 1; $index <= $count; $index++) {
            $entry = new Entry(
                $feed,
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
