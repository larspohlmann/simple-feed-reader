<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Pass;

use App\Service\Curation\YourStream\Model\RarityWindowModel;

/** One rarity refresh over many accounts: the window they share, read once, and the one stemming they share. */
final readonly class RaritySweepPass
{
    public function __construct(public EntryStemming $stemming, public RarityWindowModel $window)
    {
    }
}
