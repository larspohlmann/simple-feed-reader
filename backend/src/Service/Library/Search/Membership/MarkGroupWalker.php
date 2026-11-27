<?php

declare(strict_types=1);

namespace App\Service\Library\Search\Membership;

use App\Repository\EntryMembershipSweepRepository;
use App\Service\Library\Search\Membership\Model\MarkGroupsModel;
use App\Service\Library\Search\Membership\Model\MarkGroupWalkReportModel;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use App\Service\Library\Search\Membership\Pass\MarkGroupWalk\MarkGroupWalkInterface;
use App\Service\Library\Search\Membership\Pass\SweepTally;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Walks the entry ids above each group's mark up to the settled ceiling in chunks, storing each chunk's matches and
 * advancing the marks, within a budget. Both membership sweeps run on it.
 */
final readonly class MarkGroupWalker
{
    public const int CHUNK = 500;

    public function __construct(
        private EntryMembershipSweepRepository $entries,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Lowest mark first. A group walks only up to the next group's mark, then joins it, so every chunk is matched
     * once and a backfill never starves.
     *
     * @template TMember
     * @template TMatches
     *
     * @param MarkGroupsModel<TMember>                  $groups
     * @param MarkGroupWalkInterface<TMember, TMatches> $walk
     */
    public function walk(
        MarkGroupsModel $groups,
        SweepBudgetModel $budget,
        MarkGroupWalkInterface $walk,
    ): MarkGroupWalkReportModel {
        $tally = new SweepTally($budget->deadlineFrom($this->clock->now()));
        $marks = array_keys($groups->membersByMark);
        foreach ($marks as $index => $mark) {
            $group = $groups->membersByMark[$mark];
            $walk->joinGroup($group);
            $tally->recordGroup(\count($group));
            if (!$this->walkGroup($walk, $mark, $marks[$index + 1] ?? $groups->ceiling, $tally)) {
                return $tally->stoppedShort();
            }
        }

        return $tally->caughtUp();
    }

    /**
     * @template TMember
     * @template TMatches
     *
     * @param MarkGroupWalkInterface<TMember, TMatches> $walk
     *
     * @return bool false when the budget or a failure stopped it short
     */
    private function walkGroup(MarkGroupWalkInterface $walk, int $mark, int $stopAt, SweepTally $tally): bool
    {
        while ($mark < $stopAt) {
            if ($this->clock->now() >= $tally->deadline) {
                return false;
            }
            $chunk = $this->entries->idsBetween($mark, $stopAt, self::CHUNK);
            if ($chunk === []) {
                $walk->advanceMarks($stopAt);

                return true;
            }
            if (!$this->applyChunk($walk, $chunk, $tally)) {
                return false;
            }
            $mark = array_last($chunk);
        }

        return true;
    }

    /**
     * @template TMember
     * @template TMatches
     *
     * @param MarkGroupWalkInterface<TMember, TMatches> $walk
     * @param non-empty-list<int>                       $chunk
     */
    private function applyChunk(MarkGroupWalkInterface $walk, array $chunk, SweepTally $tally): bool
    {
        $lastId = array_last($chunk);
        try {
            $matches = $walk->matchChunk($chunk);
            // Insert and mark advance share one transaction: a run that dies here leaves neither half-inserted rows
            // nor a skipped chunk.
            $inserted = $this->entityManager->wrapInTransaction(static function () use ($walk, $matches, $lastId): int {
                $inserted = $walk->insertMatches($matches);
                $walk->advanceMarks($lastId);

                return $inserted;
            });
        } catch (\Throwable $exception) {
            $walk->logChunkFailure($exception);

            return false;
        }
        $tally->recordChunk(\count($chunk), $inserted);

        return true;
    }
}
