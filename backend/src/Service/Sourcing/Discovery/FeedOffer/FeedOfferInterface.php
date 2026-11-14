<?php

declare(strict_types=1);

namespace App\Service\Discovery\FeedOffer;

use App\Service\Discovery\Model\FeedCandidateModel;

/** A feed a fetched page implies without advertising it; discovery lists every offer after the advertised links. */
interface FeedOfferInterface
{
    public function offer(string $body, string $pageUrl): ?FeedCandidateModel;
}
