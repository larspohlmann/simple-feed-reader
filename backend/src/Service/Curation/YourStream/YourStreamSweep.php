<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream;

use App\Repository\YourStreamSourceRepository;
use App\Repository\YourStreamStateRepository;
use App\Repository\YourStreamWordRepository;
use App\Service\Curation\YourStream\Factory\EntryStemmingFactory;
use App\Service\Curation\YourStream\Model\YourStreamSweepReportModel;
use App\Service\Curation\YourStream\Pass\StreamSweepPass;
use App\Service\Curation\YourStream\Pass\YourStreamMarkGroupWalk;
use App\Service\Curation\YourStream\YourStreamMembershipWriter\YourStreamMembershipWriterInterface;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Library\Search\Membership\MarkGroupWalker;
use App\Service\Library\Search\Membership\Model\MarkGroupsModel;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use App\Service\Library\Search\Membership\SettledCeiling;
use Psr\Log\LoggerInterface;

final readonly class YourStreamSweep
{
    public const int PUBLISHED_WINDOW_HOURS = 48;

    public function __construct(
        private YourStreamStateRepository $states,
        private YourStreamSourceRepository $sources,
        private YourStreamWordRepository $words,
        private SettledCeiling $ceiling,
        private MarkGroupWalker $walker,
        private EntryStemmingFactory $stemmingFactory,
        private YourStreamChunkMatcher $matcher,
        private YourStreamMembershipWriterInterface $memberships,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function sweep(SweepBudgetModel $budget): YourStreamSweepReportModel
    {
        $ceiling = $this->ceiling->readSettledCeiling();
        $now = $this->clock->now();
        $publishedSince = $now->modify(\sprintf('-%d hours', self::PUBLISHED_WINDOW_HOURS));
        $marksByUserId = $this->raiseLaggards($this->states->findBelowMark($ceiling, $now), $ceiling, $publishedSince);
        $groups = MarkGroupsModel::fromMembers(
            $ceiling,
            array_keys($marksByUserId),
            static fn (int $userId): int => $marksByUserId[$userId],
        );
        $walk = new YourStreamMarkGroupWalk(
            new StreamSweepPass($this->stemmingFactory->create(), $publishedSince),
            $this->words,
            $this->states,
            $this->matcher,
            $this->memberships,
            $this->clock,
            $this->logger,
        );

        return YourStreamSweepReportModel::fromWalk($this->walker->walk($groups, $budget, $walk));
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
        $floor = min($ceiling, $this->sources->findIdBeforeFirstEntrySince($publishedSince) ?? $ceiling);
        $laggards = array_filter($marksByUserId, static fn (int $mark): bool => $mark < $floor);
        $this->states->advanceMarks(array_keys($laggards), $floor);
        $raised = array_map(static fn (int $mark): int => max($mark, $floor), $marksByUserId);

        return array_filter($raised, static fn (int $mark): bool => $mark < $ceiling);
    }
}
