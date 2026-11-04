<?php

declare(strict_types=1);

namespace App\Service\Trending\Model;

use App\Enum\TrendingWindow;

/** The sidebar badge: how many stories of the window the reader has not opened (#2017). */
final readonly class TrendingUnopenedModel
{
    public function __construct(public TrendingWindow $window, public int $unopened)
    {
    }
}
