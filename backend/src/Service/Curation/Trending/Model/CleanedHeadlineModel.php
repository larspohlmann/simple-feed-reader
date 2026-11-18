<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class CleanedHeadlineModel
{
    public function __construct(public string $text, public bool $markerFree)
    {
    }
}
