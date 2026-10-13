<?php

declare(strict_types=1);

namespace App\Service\Refresh\PassFetcher;

use App\Entity\SocialFeed;
use App\Service\Refresh\Model\PassOutcome\PassOutcomeModelInterface;
use App\Service\Refresh\Pass\RefreshPass;

interface PassFetcherInterface
{
    /**
     * Each started feed's outcome under its feed id, as it lands. Every started feed yields exactly one. Abandoning the
     * iterator cancels what is in flight.
     *
     * @param array<int, SocialFeed> $socialFeedsByFeedId the pass's private feeds' rows, each with its account loaded
     *
     * @return iterable<int|string, PassOutcomeModelInterface>
     */
    public function fetchAll(RefreshPass $pass, array $socialFeedsByFeedId): iterable;
}
