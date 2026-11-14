<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh\Model\RefreshTarget;

/**
 * @phpstan-sealed EveryFeedTargetModel|FeedTargetModel|TagTargetModel|NetworkTargetModel|FeedGroupTargetModel|NeverFetchedTargetModel
 */
interface RefreshTargetModelInterface
{
    public function runKey(): string;
}
