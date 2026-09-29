<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Service\Refresh\Model\RefreshReportModel;
use App\Service\Refresh\Model\RefreshRequestModel;
use App\Service\Refresh\RefreshRunner\RefreshRunnerInterface;

/**
 * Hands out one prepared report per call. A double rather than a mock so the
 * expectations read as "given these slices" instead of as call counts — and
 * because RefreshRunner is final, which is why the interface exists at all.
 */
final class FakeRefreshRunner implements RefreshRunnerInterface
{
    /** @var list<RefreshRequestModel> */
    public array $requests = [];

    /** @var list<RefreshReportModel> */
    private array $reports;

    public function __construct(RefreshReportModel ...$reports)
    {
        $this->reports = array_values($reports);
    }

    public function run(RefreshRequestModel $request): RefreshReportModel
    {
        $this->requests[] = $request;
        $report = array_shift($this->reports);
        if (null === $report) {
            throw new \LogicException('The runner was asked for more slices than the test prepared.');
        }

        return $report;
    }
}
