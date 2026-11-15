<?php

declare(strict_types=1);

namespace App\Service\Operations\LinkResolutionBackfill;

use App\Repository\SharedLinkResolutionRepository;
use App\Service\Content\LinkResolution\LinkResolutionSweep;
use App\Service\Content\LinkResolution\Model\LinkResolutionBudgetModel;
use App\Service\Content\LinkResolution\Model\LinkResolutionReportModel;
use App\Service\Foundation\Url\Support\ShortLinkShape;
use App\Service\Operations\LinkResolutionBackfill\Model\LinkResolutionBackfillReportModel;

/** One-off (#2236): queues every stored short link written before resolution existed, then drains the queue. */
final readonly class SharedLinkResolutionBackfill
{
    private const int LINKS_PER_PASS = 50;

    public function __construct(
        private SharedLinkResolutionRepository $linkResolutions,
        private LinkResolutionSweep $linkResolutionSweep,
        private int $batchSize = 500,
    ) {
    }

    public function count(): int
    {
        return array_sum(array_map(\count(...), iterator_to_array($this->unqueuedCandidates(), false)));
    }

    public function run(): LinkResolutionBackfillReportModel
    {
        $queued = 0;
        foreach ($this->unqueuedCandidates() as $linkIds) {
            $this->linkResolutions->queue($linkIds);
            $queued += \count($linkIds);
        }

        return new LinkResolutionBackfillReportModel($queued, $this->drained());
    }

    /** @return \Generator<list<int>> each batch's short-link ids */
    private function unqueuedCandidates(): \Generator
    {
        $afterId = 0;
        do {
            $urls = $this->linkResolutions->unqueuedUrlsAfterId($afterId, $this->batchSize);
            $afterId = $urls === [] ? $afterId : (int) array_key_last($urls);
            yield array_keys(array_filter($urls, ShortLinkShape::fits(...)));
        } while (\count($urls) === $this->batchSize);
    }

    private function drained(): LinkResolutionReportModel
    {
        $report = LinkResolutionReportModel::none();
        do {
            $pass = $this->linkResolutionSweep->resolvePending(LinkResolutionBudgetModel::links(self::LINKS_PER_PASS));
            $report = $report->plus($pass);
        } while ($pass->considered > 0);

        return $report;
    }
}
