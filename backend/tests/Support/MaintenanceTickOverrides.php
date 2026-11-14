<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Content\LinkCard\LinkCardSweep;
use App\Service\Ingestion\Refresh\RefreshRunner\RefreshRunner;
use App\Service\Library\Search\Membership\SavedSearchMatcher\SavedSearchMatcherInterface;
use App\Service\Social\Social\SocialCandidates\SocialCandidatesInterface;
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
