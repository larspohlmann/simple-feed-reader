<?php

declare(strict_types=1);

namespace App\Service\Trending\Model;

final readonly class TrendingCountsModel
{
    public function __construct(public int $day, public int $week)
    {
    }
}
