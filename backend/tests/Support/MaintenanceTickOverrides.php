<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\LinkCard\LinkCardSweep;
use App\Service\Refresh\RefreshRunner\RefreshRunner;
use App\Service\Search\Membership\SavedSearchMatcher\SavedSearchMatcherInterface;
use App\Service\Social\SocialCandidates\SocialCandidatesInterface;
use Psr\Clock\ClockInterface;

/** What a MaintenanceTickTest case swaps into a tick wired by hand; whatever it leaves null stays the default. */
final readonly class MaintenanceTickOverrides
{
    public function __construct(
        public RefreshRunner $refreshRunner,
        public ClockInterface $clock,
        public int $windowSeconds,
        public ?LinkCardSweep $linkCardSweep = null,
        public ?StubFeedFetcher $appViewFetcher = null,
        public ?SavedSearchMatcherInterface $matcher = null,
        public ?SocialCandidatesInterface $network = null,
    ) {
    }
}
