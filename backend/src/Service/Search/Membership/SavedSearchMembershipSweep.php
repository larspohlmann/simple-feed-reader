<?php

declare(strict_types=1);

namespace App\Service\Search\Membership;

use App\Entity\SavedSearch;
use App\Repository\EntryMembershipSweepRepository;
use App\Repository\SavedSearchRepository;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\SavedSearchTerms;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Fills saved_search_entry incrementally (#1116): each search carries a
 * high-water mark; a run walks entry ids above it to a settled ceiling in
 * chunks, inserts the matches and advances the mark, within a budget.
 */
final readonly class SavedSearchMembershipSweep
{
    public const int CHUNK = 500;

    /** How old an entry must be before it is matched, so an engine's async indexing has landed. */
    public const int SETTLE_SECONDS = 60;

    public function __construct(
        private SavedSearchRepository $searches,
        private EntryMembershipSweepRepository $entries,
        private SavedSearchMembershipWriter $memberships,
        private SavedSearchMatcher $matcher,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function sweep(SweepBudget $budget): SavedSearchMembershipSweepReport
    {
        $ceiling = $this->ceiling();

        return $this->walkGroups(self::groupedByMark($this->searches->findBelowMark($ceiling)), $ceiling, $budget);
    }

    public function sweepOne(SavedSearch $search, SweepBudget $budget): SavedSearchMembershipSweepReport
    {
        $ceiling = $this->ceiling();
        $mark = $search->matchedUpToEntryId();

        return $this->walkGroups($mark < $ceiling ? [$mark => [$search]] : [], $ceiling, $budget);
    }

    private function ceiling(): int
    {
        return $this->entries->settledCeilingId(
            $this->clock->now()->modify(\sprintf('-%d seconds', self::SETTLE_SECONDS)),
        );
    }

    /**
     * @param list<SavedSearch> $due
     *
     * @return array<int, non-empty-list<SavedSearch>> mark => the searches at it, ascending
     */
    private static function groupedByMark(array $due): array
    {
        $groups = [];
        foreach ($due as $search) {
            $groups[$search->matchedUpToEntryId()][] = $search;
        }
        ksort($groups);

        return $groups;
    }

    /**
     * Lowest mark first. A group walks only up to the next group's mark, then
     * joins it, so every chunk is matched once and a backfill never starves.
     *
     * @param array<int, non-empty-list<SavedSearch>> $groupsByMark
     */
    private function walkGroups(
        array $groupsByMark,
        int $ceiling,
        SweepBudget $budget,
    ): SavedSearchMembershipSweepReport {
        $tally = new SweepTally();
        $deadline = $budget->deadlineFrom($this->clock->now());
        $marks = array_keys($groupsByMark);
        $walking = [];
        foreach ($marks as $index => $mark) {
            $walking = [...$walking, ...$groupsByMark[$mark]];
            $tally->searchesSwept += \count($groupsByMark[$mark]);
            if (!$this->walkGroup($walking, $mark, $marks[$index + 1] ?? $ceiling, $deadline, $tally)) {
                return $tally->stoppedShort();
            }
        }

        return $tally->caughtUp();
    }

    /**
     * Walks one group from $mark to $stopAt; false when the budget or a
     * failure stopped it short.
     *
     * @param non-empty-list<SavedSearch> $group
     */
    private function walkGroup(
        array $group,
        int $mark,
        int $stopAt,
        \DateTimeImmutable $deadline,
        SweepTally $tally,
    ): bool {
        while ($mark < $stopAt) {
            if ($this->clock->now() >= $deadline) {
                return false;
            }
            $chunk = $this->entries->idsBetween($mark, $stopAt, self::CHUNK);
            if ($chunk === []) {
                $this->searches->advanceMarks(self::idsOf($group), $stopAt);

                return true;
            }
            if (!$this->applyChunk($group, $chunk, $tally)) {
                return false;
            }
            $mark = $chunk[array_key_last($chunk)];
        }

        return true;
    }

    /**
     * @param non-empty-list<SavedSearch> $group
     * @param non-empty-list<int>         $chunk
     */
    private function applyChunk(array $group, array $chunk, SweepTally $tally): bool
    {
        try {
            $matches = $this->matcher->matchingIds(array_map(SavedSearchTerms::termOf(...), $group), $chunk);
            $tally->matchesInserted += $this->store($group, $chunk, $matches);
        } catch (SearchEngineUnavailableException $e) {
            $this->logger->warning('Search engine unavailable; the membership sweep stops here and retries next run.', [
                'exception' => $e,
            ]);

            return false;
        } catch (\Throwable $e) {
            $this->logger->error('The membership sweep failed on a chunk; it stops here and retries next run.', [
                'exception' => $e,
            ]);

            return false;
        }
        $tally->entriesScanned += \count($chunk);

        return true;
    }

    /**
     * @param non-empty-list<SavedSearch> $group
     * @param non-empty-list<int>         $chunk
     * @param array<int, list<int>>       $matches
     *
     * @return int the pairs added
     */
    private function store(array $group, array $chunk, array $matches): int
    {
        $now = $this->clock->now();
        $lastId = $chunk[array_key_last($chunk)];

        // Insert and mark advance share one transaction: a run that dies here
        // leaves neither half-inserted rows nor a skipped chunk.
        return $this->em->wrapInTransaction(function () use ($group, $matches, $now, $lastId): int {
            $inserted = $this->memberships->insertMissing($matches, $now);
            $this->searches->advanceMarks(self::idsOf($group), $lastId);

            return $inserted;
        });
    }

    /**
     * @param non-empty-list<SavedSearch> $group
     *
     * @return non-empty-list<int>
     */
    private static function idsOf(array $group): array
    {
        return array_map(static fn (SavedSearch $search): int => (int) $search->getId(), $group);
    }
}
