<?php

declare(strict_types=1);

namespace App\Service\Search\Membership;

use App\Entity\SavedSearch;
use App\Repository\EntryMembershipSweepRepository;
use App\Repository\SavedSearchEntryMembershipRepository;
use App\Repository\SavedSearchRepository;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\SavedSearchTerms;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Fills saved_search_entry incrementally (#1116): every search carries a
 * high-water mark, the run walks entry ids above it up to a settled ceiling
 * in chunks, asks the matcher which ids match, inserts the rows and advances
 * the mark. Searches at the same mark walk together, so once caught up a run
 * is one matcher call per chunk for all of them. Budget-bounded; the mark is
 * where the next run continues.
 */
final readonly class SavedSearchMembershipSweep
{
    public const int CHUNK = 500;

    /** How old an entry must be before it is matched, so an engine's async indexing has landed. */
    public const int SETTLE_SECONDS = 60;

    public function __construct(
        private SavedSearchRepository $searches,
        private EntryMembershipSweepRepository $entries,
        private SavedSearchEntryMembershipRepository $memberships,
        private SavedSearchMatcher $matcher,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function sweep(SweepBudget $budget): SavedSearchMembershipSweepReport
    {
        $ceiling = $this->ceiling();

        return $this->walkGroups($this->searches->findBelowMark($ceiling), $ceiling, $budget);
    }

    public function sweepOne(SavedSearch $search, SweepBudget $budget): SavedSearchMembershipSweepReport
    {
        $ceiling = $this->ceiling();
        $due = $search->matchedUpToEntryId() < $ceiling ? [$search] : [];

        return $this->walkGroups($due, $ceiling, $budget);
    }

    private function ceiling(): int
    {
        return $this->entries->settledCeilingId(
            $this->clock->now()->modify(\sprintf('-%d seconds', self::SETTLE_SECONDS)),
        );
    }

    /**
     * @param list<SavedSearch> $due
     */
    private function walkGroups(array $due, int $ceiling, SweepBudget $budget): SavedSearchMembershipSweepReport
    {
        $tally = new SweepTally();
        $deadline = $budget->deadlineFrom($this->clock->now());
        foreach (self::groupedByMark($due) as $group) {
            $tally->searchesSwept += \count($group);
            if (!$this->walkGroup($group, $ceiling, $deadline, $tally)) {
                return $tally->toReport(false);
            }
        }

        return $tally->toReport(true);
    }

    /**
     * @param list<SavedSearch> $due already ordered by mark, then id
     *
     * @return list<non-empty-list<SavedSearch>>
     */
    private static function groupedByMark(array $due): array
    {
        $groups = [];
        foreach ($due as $search) {
            $groups[$search->matchedUpToEntryId()][] = $search;
        }

        return array_values($groups);
    }

    /**
     * Walks one mark group to the ceiling; false when the budget or the
     * engine stopped it short.
     *
     * @param non-empty-list<SavedSearch> $group
     */
    private function walkGroup(array $group, int $ceiling, \DateTimeImmutable $deadline, SweepTally $tally): bool
    {
        $mark = $group[0]->matchedUpToEntryId();
        while ($mark < $ceiling) {
            if ($this->clock->now() >= $deadline) {
                return false;
            }
            $chunk = $this->entries->idsBetween($mark, $ceiling, self::CHUNK);
            if ($chunk === []) {
                $this->advance($group, $ceiling);

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
        } catch (SearchEngineUnavailableException $e) {
            $this->logger->warning('Search engine unavailable; the membership sweep stops here and retries next run.', [
                'exception' => $e,
            ]);

            return false;
        }

        $now = $this->clock->now();
        $lastId = $chunk[array_key_last($chunk)];
        // Insert and mark advance share one transaction: a run that dies here
        // leaves neither half-inserted rows nor a skipped chunk.
        $this->em->wrapInTransaction(function () use ($group, $matches, $now, $lastId, $tally): void {
            foreach ($group as $search) {
                $searchId = (int) $search->getId();
                $tally->matchesInserted += $this->memberships->insertMissing(
                    $searchId,
                    $matches[$searchId] ?? [],
                    $now,
                );
            }
            $this->advance($group, $lastId);
        });
        $tally->entriesScanned += \count($chunk);

        return true;
    }

    /** @param non-empty-list<SavedSearch> $group */
    private function advance(array $group, int $entryId): void
    {
        foreach ($group as $search) {
            $search->advanceMatchedUpTo($entryId);
        }
        $this->em->flush();
    }
}
