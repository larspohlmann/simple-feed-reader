<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model\PassOutcome;

use App\Entity\Feed;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Refresh\Model\FeedRefreshResultModel;
use App\Service\Refresh\Pass\PassOutcomeStore;

/** An RSS or Atom feed's raw fetch outcome. */
final readonly class FeedPassOutcomeModel implements PassOutcomeModelInterface
{
    public function __construct(public FetchOutcomeModel $outcome)
    {
    }

    /** @throws \DateMalformedStringException */
    public function storeIn(PassOutcomeStore $store, Feed $feed): FeedRefreshResultModel
    {
        return $store->storeFetched($feed, $this->outcome);
    }
}
