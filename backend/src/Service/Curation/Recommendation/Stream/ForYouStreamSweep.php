<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream;

use App\Repository\ForYouStreamSourceRepository;
use App\Repository\InterestTermRepository;
use App\Repository\InterestTermSetRepository;
use App\Service\Curation\Recommendation\Stream\Factory\EntryStemmingFactory;
use App\Service\Curation\Recommendation\Stream\ForYouStreamMembershipWriter\ForYouStreamMembershipWriterInterface;
use App\Service\Curation\Recommendation\Stream\Model\ForYouStreamSweepReportModel;
use App\Service\Curation\Recommendation\Stream\Pass\ForYouStreamMarkGroupWalk;
use App\Service\Curation\Recommendation\Stream\Pass\StreamSweepPass;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Library\Search\Membership\MarkGroupWalker;
use App\Service\Library\Search\Membership\Model\MarkGroupsModel;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use App\Service\Library\Search\Membership\SettledCeiling;
use Psr\Log\LoggerInterface;

/** Fills for_you_stream_entry incrementally: each account's term set carries a mark the shared walk advances. */
final readonly class ForYouStreamSweep
{
    public const int PUBLISHED_WINDOW_HOURS = 48;

    public function __construct(
        private InterestTermSetRepository $sets,
        private ForYouStreamSourceRepository $sources,
        private InterestTermRepository $storedTerms,
        private SettledCeiling $ceiling,
        private MarkGroupWalker $walker,
        private EntryStemmingFactory $stemmingFactory,
        private ForYouStreamChunkMatcher $matcher,
        private ForYouStreamMembershipWriterInterface $memberships,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function sweep(SweepBudgetModel $budget): ForYouStreamSweepReportModel
    {
        $ceiling = $this->ceiling->readSettledCeiling();
        $now = $this->clock->now();
        $publishedSince = $now->modify(\sprintf('-%d hours', self::PUBLISHED_WINDOW_HOURS));
        $this->sets->advanceMarks($this->sets->findDisabledBelowMark($ceiling, $now), $ceiling);
        $marksByUserId = $this->raiseLaggards($this->sets->findBelowMark($ceiling, $now), $ceiling, $publishedSince);
        $groups = MarkGroupsModel::fromMembers(
            $ceiling,
            array_keys($marksByUserId),
            static fn (int $userId): int => $marksByUserId[$userId],
        );
        $walk = new ForYouStreamMarkGroupWalk(
            new StreamSweepPass($this->stemmingFactory->create(), $publishedSince),
            $this->storedTerms,
            $this->sets,
            $this->matcher,
            $this->memberships,
            $this->clock,
            $this->logger,
        );

        return ForYouStreamSweepReportModel::fromWalk($this->walker->walk($groups, $budget, $walk));
    }

    /**
     * Every entry below the floor was published before the window, so a mark behind it moves up to it unwalked: an
     * account back after weeks does not walk weeks of ids.
     *
     * @param array<int, int> $marksByUserId
     *
     * @return array<int, int> the marks still below the ceiling, none below the floor
     */
    private function raiseLaggards(array $marksByUserId, int $ceiling, \DateTimeImmutable $publishedSince): array
    {
        if ([] === $marksByUserId) {
            return [];
        }
        $floor = min($ceiling, ($this->sources->findFirstEntryIdSince($publishedSince) ?? $ceiling + 1) - 1);
        $laggards = array_filter($marksByUserId, static fn (int $mark): bool => $mark < $floor);
        $this->sets->advanceMarks(array_keys($laggards), $floor);
        $raised = array_map(static fn (int $mark): int => max($mark, $floor), $marksByUserId);

        return array_filter($raised, static fn (int $mark): bool => $mark < $ceiling);
    }
}
