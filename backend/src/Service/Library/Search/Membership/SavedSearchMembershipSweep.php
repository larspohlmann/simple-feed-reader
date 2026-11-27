<?php

declare(strict_types=1);

namespace App\Service\Library\Search\Membership;

use App\Entity\SavedSearch;
use App\Repository\SavedSearchRepository;
use App\Service\Library\Search\Membership\Model\MarkGroupsModel;
use App\Service\Library\Search\Membership\Model\SavedSearchMembershipSweepReportModel;
use App\Service\Library\Search\Membership\Model\SweepBudgetModel;
use App\Service\Library\Search\Membership\Pass\SavedSearchMarkGroupWalk;
use App\Service\Library\Search\Membership\SavedSearchMatcher\SavedSearchMatcherInterface;
use App\Service\Library\Search\Membership\SavedSearchMembershipWriter\SavedSearchMembershipWriterInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/** Fills saved_search_entry incrementally: each search carries a high-water mark the shared walk advances. */
final readonly class SavedSearchMembershipSweep
{
    public function __construct(
        private SavedSearchRepository $searches,
        private SettledCeiling $settledCeiling,
        private MarkGroupWalker $walker,
        private SavedSearchMembershipWriterInterface $memberships,
        private SavedSearchMatcherInterface $matcher,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function sweep(SweepBudgetModel $budget): SavedSearchMembershipSweepReportModel
    {
        $ceiling = $this->settledCeiling->readSettledCeiling();

        return $this->walk(self::groupByMark($ceiling, $this->searches->findBelowMark($ceiling)), $budget);
    }

    public function sweepOne(SavedSearch $search, SweepBudgetModel $budget): SavedSearchMembershipSweepReportModel
    {
        $ceiling = $this->settledCeiling->readSettledCeiling();
        $due = $search->matchedUpToEntryId() < $ceiling ? [$search] : [];

        return $this->walk(self::groupByMark($ceiling, $due), $budget);
    }

    /**
     * @param list<SavedSearch> $due
     *
     * @return MarkGroupsModel<SavedSearch>
     */
    private static function groupByMark(int $ceiling, array $due): MarkGroupsModel
    {
        return MarkGroupsModel::fromMembers(
            $ceiling,
            $due,
            static fn (SavedSearch $search): int => $search->matchedUpToEntryId(),
        );
    }

    /** @param MarkGroupsModel<SavedSearch> $groups */
    private function walk(MarkGroupsModel $groups, SweepBudgetModel $budget): SavedSearchMembershipSweepReportModel
    {
        $walk = new SavedSearchMarkGroupWalk(
            $this->searches,
            $this->matcher,
            $this->memberships,
            $this->clock,
            $this->logger,
        );

        return SavedSearchMembershipSweepReportModel::fromWalk($this->walker->walk($groups, $budget, $walk));
    }
}
