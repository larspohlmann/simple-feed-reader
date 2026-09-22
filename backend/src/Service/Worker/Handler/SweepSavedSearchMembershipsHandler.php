<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Search\Membership\SavedSearchMembershipSweep;
use App\Service\Search\Membership\SweepBudget;
use App\Service\Worker\Message\SweepSavedSearchMemberships;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SweepSavedSearchMembershipsHandler
{
    /** The worker has no FastCGI window to fit; one firing may walk a whole backfill. */
    private const int BUDGET_SECONDS = 60;

    public function __construct(
        private SavedSearchMembershipSweep $sweep,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SweepSavedSearchMemberships $message): void
    {
        $report = $this->sweep->sweep(SweepBudget::seconds(self::BUDGET_SECONDS));
        $this->logger->info('Worker saved-search membership sweep finished.', ['report' => $report->toArray()]);
    }
}
