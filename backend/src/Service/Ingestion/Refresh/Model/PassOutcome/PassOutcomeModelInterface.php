<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh\Model\PassOutcome;

use App\Entity\Feed;
use App\Service\Ingestion\Refresh\Model\FeedRefreshResultModel;
use App\Service\Ingestion\Refresh\Pass\PassOutcomeStore;

/**
 * One started feed's outcome as its lane settled it; it knows which of the store's paths it takes.
 *
 * @phpstan-sealed FeedPassOutcomeModel|TimelinePassOutcomeModel
 */
interface PassOutcomeModelInterface
{
    /** @throws \DateMalformedStringException */
    public function storeIn(PassOutcomeStore $store, Feed $feed): FeedRefreshResultModel;
}
