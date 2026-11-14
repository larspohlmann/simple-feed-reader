<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh\Model\PassOutcome;

use App\Entity\Feed;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Ingestion\Refresh\Model\FeedRefreshResultModel;
use App\Service\Ingestion\Refresh\Pass\PassOutcomeStore;

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
