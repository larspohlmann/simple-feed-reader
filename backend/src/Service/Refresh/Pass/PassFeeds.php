<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Refresh\Pass\PassLane\PassLaneInterface;

/** One pass's feeds as its budget starts them, each feed id's lane decided here, once. */
final readonly class PassFeeds
{
    /** @var array<int, true> */
    private array $privateFeedIds;

    public function __construct(
        private RefreshPass $pass,
        private PassLaneInterface $feedLane,
        private PassLaneInterface $timelineLane,
    ) {
        $this->privateFeedIds = array_fill_keys(self::privateFeedIdsOf($pass), true);
    }

    /** @return list<int> every private feed's id, started or not */
    public static function privateFeedIdsOf(RefreshPass $pass): array
    {
        $privateFeedIds = [];
        foreach ($pass->feeds as $feed) {
            if (SourceFormat::isSocial($feed->getSourceFormat())) {
                $privateFeedIds[] = $feed->requireId();
            }
        }

        return $privateFeedIds;
    }

    /** @return \Generator<int, Feed, mixed, void> */
    public function started(): \Generator
    {
        return $this->pass->startedFeeds();
    }

    public function laneOf(int|string $feedId): PassLaneInterface
    {
        return isset($this->privateFeedIds[$feedId]) ? $this->timelineLane : $this->feedLane;
    }

    /** @return list<PassLaneInterface> */
    public function lanes(): array
    {
        return [$this->feedLane, $this->timelineLane];
    }
}
