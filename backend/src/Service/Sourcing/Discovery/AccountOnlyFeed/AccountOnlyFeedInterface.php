<?php

declare(strict_types=1);

namespace App\Service\Discovery\AccountOnlyFeed;

use App\Entity\User;
use App\Service\Discovery\Model\FeedCandidateModel;

/** A source offering some URLs as a feed only a connected account can read, asked before discovery fetches. */
interface AccountOnlyFeedInterface
{
    /** @return FeedCandidateModel|null null when the URL is not this source's to offer */
    public function candidateFor(User $user, string $url): ?FeedCandidateModel;
}
