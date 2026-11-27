<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream;

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
        $marksByUserId = $this->sets->findBelowMark($ceiling, $now);
        $groups = MarkGroupsModel::fromMembers(
            $ceiling,
            array_keys($marksByUserId),
            static fn (int $userId): int => $marksByUserId[$userId],
        );
        $walk = new ForYouStreamMarkGroupWalk(
            new StreamSweepPass(
                $this->stemmingFactory->create(),
                $now->modify(\sprintf('-%d hours', self::PUBLISHED_WINDOW_HOURS)),
            ),
            $this->storedTerms,
            $this->sets,
            $this->matcher,
            $this->memberships,
            $this->clock,
            $this->logger,
        );

        return ForYouStreamSweepReportModel::fromWalk($this->walker->walk($groups, $budget, $walk));
    }
}
