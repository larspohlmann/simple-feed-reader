<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Library\Search\Membership\SavedSearchMatcher\SavedSearchMatcherInterface;
use Psr\Clock\ClockInterface;

/** Matches nothing and stamps the clock's reading under "memberships" at the first question, to show the order. */
final readonly class ClockStampingMatcher implements SavedSearchMatcherInterface
{
    public function __construct(private StageStamps $stamps, private ClockInterface $clock)
    {
    }

    public function matchingIds(array $searches, array $candidateEntryIds): array
    {
        $this->stamps->record('memberships', $this->clock->now());

        return [];
    }
}
