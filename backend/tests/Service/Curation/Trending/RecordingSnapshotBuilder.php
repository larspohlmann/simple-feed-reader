<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Exception\TrendingBuildBusyException;
use App\Service\Curation\Trending\Model\StoryMatchModel;
use App\Service\Curation\Trending\Model\TrendingBuildReportModel;
use App\Service\Curation\Trending\SnapshotBuilder\SnapshotBuilderInterface;

final class RecordingSnapshotBuilder implements SnapshotBuilderInterface
{
    /** @var list<array{int, TrendingWindow}> */
    public array $calls = [];

    /** @param ?\Throwable $failure what $failingUserId's builds throw; a busy lock when null */
    public function __construct(
        private readonly ?int $failingUserId = null,
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function build(int $userId, TrendingWindow $window): TrendingBuildReportModel
    {
        if ($userId === $this->failingUserId) {
            throw $this->failure ?? new TrendingBuildBusyException();
        }
        $this->calls[] = [$userId, $window];

        return new TrendingBuildReportModel(
            $userId,
            $window,
            new \DateTimeImmutable('2031-05-14 12:00:00'),
            0,
            0,
            0,
            0,
            0,
            new StoryMatchModel([], [], 0),
            0,
        );
    }
}
