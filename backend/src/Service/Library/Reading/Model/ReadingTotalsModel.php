<?php

declare(strict_types=1);

namespace App\Service\Library\Reading\Model;

final readonly class ReadingTotalsModel
{
    public function __construct(public int $viewedCount, public int $favoritesCount)
    {
    }
}
