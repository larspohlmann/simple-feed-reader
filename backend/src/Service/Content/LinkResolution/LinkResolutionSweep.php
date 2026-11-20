<?php

declare(strict_types=1);

namespace App\Service\Content\LinkResolution;

use App\Repository\SharedLinkRepository;
use App\Repository\SharedLinkResolutionRepository;
use App\Service\Content\LinkResolution\Model\LinkLandingModel;
use App\Service\Content\LinkResolution\Model\LinkResolutionBudgetModel;
use App\Service\Content\LinkResolution\Model\LinkResolutionReportModel;
use App\Service\Content\LinkResolution\Model\PendingLinkModel;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Foundation\Clock\Support\ElapsedSeconds;
use App\Service\Outbound\Fetch\Support\HostKey;
use App\Service\Outbound\Fetch\Support\LandingSite;
use Psr\Log\LoggerInterface;

/** Follows the queued short links, oldest first, within a budget; each is settled as soon as it lands. */
final readonly class LinkResolutionSweep
{
    private const int MAX_ATTEMPTS = 3;
    private const string RETRY_DELAY = '+1 hour';

    public function __construct(
        private SharedLinkResolutionRepository $linkResolutions,
        private SharedLinkRepository $sharedLinks,
        private LinkLanding $landing,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function resolvePending(LinkResolutionBudgetModel $budget): LinkResolutionReportModel
    {
        $report = LinkResolutionReportModel::none();
        foreach ($this->linkResolutions->findDuePending($this->clock->now(), $budget->links) as $link) {
            if (!$budget->fitsAfter($report->seconds)) {
                break;
            }
            $startedAt = $this->clock->now();
            $landing = $this->attempt($link);
            $report = $report->counted($landing, ElapsedSeconds::between($startedAt, $this->clock->now()));
        }

        return $report;
    }

    /** A row that fails in a way nothing foresaw is settled unresolved, so it cannot stay first in the queue. */
    private function attempt(PendingLinkModel $link): LinkLandingModel
    {
        try {
            return $this->settle($link, $this->landing->resolveLanding($link->url));
        } catch (\Throwable $exception) {
            $this->logger->error('Link resolution failed unexpectedly.', [
                'linkId' => $link->linkId,
                'url' => $link->url,
                'exception' => $exception,
            ]);

            return $this->giveUp($link);
        }
    }

    private function settle(PendingLinkModel $link, LinkLandingModel $landing): LinkLandingModel
    {
        if (!$landing->isRetryable()) {
            $this->linkResolutions->settle($link, $landing);
            $this->nameAfterLanding($link, $landing);

            return $landing;
        }
        if ($link->attempts + 1 < self::MAX_ATTEMPTS) {
            $this->linkResolutions->defer($link, $this->clock->now()->modify(self::RETRY_DELAY));

            return $landing;
        }

        return $this->giveUp($link);
    }

    private function nameAfterLanding(PendingLinkModel $link, LinkLandingModel $landing): void
    {
        if ($landing->url === null) {
            return;
        }
        $postedHost = HostKey::forUrl($link->url);
        $landingSite = LandingSite::pickHost($link->url, $landing->url);
        if ($landingSite !== $postedHost) {
            $this->sharedLinks->renameSite($link->linkId, $postedHost, $landingSite);
        }
    }

    private function giveUp(PendingLinkModel $link): LinkLandingModel
    {
        $unresolved = LinkLandingModel::unresolved();
        $this->linkResolutions->settle($link, $unresolved);

        return $unresolved;
    }
}
