<?php

declare(strict_types=1);

namespace App\Service\Content\LinkResolution;

use App\Repository\SharedLinkResolutionRepository;
use App\Service\Content\LinkResolution\Model\LinkLandingModel;
use App\Service\Content\LinkResolution\Model\LinkResolutionBudgetModel;
use App\Service\Content\LinkResolution\Model\LinkResolutionReportModel;
use App\Service\Content\LinkResolution\Model\PendingLinkModel;
use App\Service\Foundation\Clock\NaiveUtcClock;

/** Follows the queued short links, oldest first, within a budget; each is settled as soon as it lands. */
final readonly class LinkResolutionSweep
{
    private const int MAX_ATTEMPTS = 3;
    private const string RETRY_DELAY = '+1 hour';

    public function __construct(
        private SharedLinkResolutionRepository $linkResolutions,
        private LinkLanding $landing,
        private NaiveUtcClock $clock,
    ) {
    }

    public function resolvePending(LinkResolutionBudgetModel $budget): LinkResolutionReportModel
    {
        $report = LinkResolutionReportModel::none();
        foreach ($this->linkResolutions->pendingAt($this->clock->now(), $budget->links) as $link) {
            if (!$budget->fitsAfter($report->seconds)) {
                break;
            }
            $startedAt = $this->clock->now();
            $landing = $this->settle($link, $this->landing->of($link->url));
            $report = $report->counted($landing, self::secondsSince($startedAt, $this->clock->now()));
        }

        return $report;
    }

    private function settle(PendingLinkModel $link, LinkLandingModel $landing): LinkLandingModel
    {
        if (!$landing->isRetryable()) {
            $this->linkResolutions->settle($link, $landing);

            return $landing;
        }
        if ($link->attempts + 1 < self::MAX_ATTEMPTS) {
            $this->linkResolutions->defer($link, $this->clock->now()->modify(self::RETRY_DELAY));

            return $landing;
        }
        $this->linkResolutions->settle($link, LinkLandingModel::unresolved());

        return LinkLandingModel::unresolved();
    }

    private static function secondsSince(\DateTimeImmutable $start, \DateTimeImmutable $end): float
    {
        return (float) $end->format('U.u') - (float) $start->format('U.u');
    }
}
