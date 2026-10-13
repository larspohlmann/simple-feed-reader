<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass;

use App\Entity\SocialFeed;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;

/**
 * Hands the timeline fetcher a pass's private feeds as the budget lets each start. A feed whose social feed is gone
 * fails without a request, and its outcome joins the landed ones.
 */
final class PrivateFeedSupply
{
    /** @var array<int, TimelineOutcomeModelInterface> */
    private array $orphaned = [];

    /** @param array<int, SocialFeed> $socialFeedsByFeedId */
    public function __construct(private readonly RefreshPass $pass, private readonly array $socialFeedsByFeedId)
    {
    }

    /** @return \Generator<int, SocialFeed> */
    public function socialFeeds(): \Generator
    {
        foreach ($this->pass->socialFeeds() as $feedId => $feed) {
            $socialFeed = $this->socialFeedsByFeedId[$feedId] ?? null;
            if (null === $socialFeed) {
                $this->orphaned[$feedId] = new FailedTimelineModel(new PrivateFeedOrphanedException());
                continue;
            }

            yield $feedId => $socialFeed;
        }
    }

    /**
     * @param iterable<int|string, TimelineOutcomeModelInterface> $landed
     *
     * @return \Generator<int|string, TimelineOutcomeModelInterface>
     */
    public function withOrphans(iterable $landed): \Generator
    {
        foreach ($landed as $feedId => $outcome) {
            yield from $this->drainOrphaned();
            yield $feedId => $outcome;
        }

        yield from $this->drainOrphaned();
    }

    /** @return \Generator<int, TimelineOutcomeModelInterface> */
    private function drainOrphaned(): \Generator
    {
        $orphaned = $this->orphaned;
        $this->orphaned = [];

        yield from $orphaned;
    }
}
