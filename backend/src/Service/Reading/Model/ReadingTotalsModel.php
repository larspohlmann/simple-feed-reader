<?php

declare(strict_types=1);

namespace App\Service\Reading\Model;

final readonly class ReadingTotalsModel
{
    public function __construct(public int $viewedCount, public int $favoritesCount)
    {
    }
}
