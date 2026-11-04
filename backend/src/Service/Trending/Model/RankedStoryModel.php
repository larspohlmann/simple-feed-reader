<?php

declare(strict_types=1);

namespace App\Service\Trending\Model;

final readonly class RankedStoryModel
{
    /** @param int $outletCount distinct feeds of this reader in the story, social accounts included */
    public function __construct(public int $storyId, public int $outletCount)
    {
    }
}
