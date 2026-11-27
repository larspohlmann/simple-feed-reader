<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream;

use App\Entity\InterestTermSet;
use App\Repository\EntryMembershipSweepRepository;
use App\Repository\InterestTermRepository;
use App\Repository\InterestTermSetRepository;
use App\Service\Curation\Recommendation\Stream\Factory\EntryStemmingFactory;
use App\Service\Curation\Recommendation\Stream\ForYouStreamMembershipWriter\ForYouStreamMembershipWriterInterface;
use App\Service\Curation\Recommendation\Stream\Model\ForYouStreamSweepReportModel;
use App\Service\Curation\Recommendation\Stream\Pass\StreamSweepPass;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use App\Service\Library\Search\Membership\SettledCeiling;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fills for_you_stream_entry incrementally: each account's term set carries a high-water mark; a run walks the
 * settled entry ids above it in chunks, stores what scores and advances the mark, within a budget.
 */
final readonly class ForYouStreamSweep
{
    public const int CHUNK = 500;
    public const int PUBLISHED_WINDOW_HOURS = 48;

    public function __construct(
        private InterestTermSetRepository $sets,
        private InterestTermRepository $storedTerms,
        private EntryMembershipSweepRepository $entries,
        private SettledCeiling $ceiling,
        private EntryStemmingFactory $stemmingFactory,
        private ForYouStreamChunkMatcher $matcher,
        private ForYouStreamMembershipWriterInterface $memberships,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function sweep(SweepBudgetModel $budget): ForYouStreamSweepReportModel
    {
        $ceiling = $this->ceiling->readSettledCeiling();
        $now = $this->clock->now();
        $pass = new StreamSweepPass(
            $this->stemmingFactory->create(),
            $now->modify(\sprintf('-%d hours', self::PUBLISHED_WINDOW_HOURS)),
            $budget->deadlineFrom($now),
        );

        return $this->walkGroups(self::groupByMark($this->sets->findBelowMark($ceiling, $now)), $ceiling, $pass);
    }

    /**
     * @param list<InterestTermSet> $due
     *
     * @return array<int, non-empty-list<int>> mark => the user ids at it, ascending
     */
    private static function groupByMark(array $due): array
    {
        $groups = [];
        foreach ($due as $set) {
            $groups[$set->matchedUpToEntryId()][] = $set->getUser()->requireId();
        }
        ksort($groups);

        return $groups;
    }

    /**
     * Lowest mark first. A group walks only up to the next group's mark, then joins it, so every chunk is
     * stemmed once and a backfill never starves.
     *
     * @param array<int, non-empty-list<int>> $userIdsByMark
     */
    private function walkGroups(array $userIdsByMark, int $ceiling, StreamSweepPass $pass): ForYouStreamSweepReportModel
    {
        $marks = array_keys($userIdsByMark);
        foreach ($marks as $index => $mark) {
            $pass->join($this->storedTerms->findTermsForUsers($userIdsByMark[$mark]));
            if (!$this->walkGroup($pass, $mark, $marks[$index + 1] ?? $ceiling)) {
                return $pass->stoppedShort();
            }
        }

        return $pass->caughtUp();
    }

    /** Walks the pass's accounts from $mark to $stopAt; false when the budget or a failure stopped it short. */
    private function walkGroup(StreamSweepPass $pass, int $mark, int $stopAt): bool
    {
        while ($mark < $stopAt) {
            if ($this->clock->now() >= $pass->deadline) {
                return false;
            }
            $chunk = $this->entries->idsBetween($mark, $stopAt, self::CHUNK);
            if ($chunk === []) {
                $this->sets->advanceMarks($pass->collectUserIds(), $stopAt);

                return true;
            }
            if (!$this->applyChunk($pass, $chunk)) {
                return false;
            }
            $mark = array_last($chunk);
        }

        return true;
    }

    /** @param non-empty-list<int> $chunk */
    private function applyChunk(StreamSweepPass $pass, array $chunk): bool
    {
        try {
            $matches = $this->matcher->matchChunk($pass, $chunk);
            $lastId = array_last($chunk);
            $inserted = $this->entityManager->wrapInTransaction(
                function () use ($pass, $matches, $lastId): int {
                    $inserted = $this->memberships->insertMissing($matches, $this->clock->now());
                    $this->sets->advanceMarks($pass->collectUserIds(), $lastId);

                    return $inserted;
                },
            );
        } catch (\Throwable $exception) {
            $this->logger->error('The For you stream sweep failed on a chunk; it stops here and retries next run.', [
                'exception' => $exception,
            ]);

            return false;
        }
        $pass->recordChunk(\count($chunk), $inserted);

        return true;
    }
}
