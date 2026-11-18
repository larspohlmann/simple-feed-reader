<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class StoryTitleModel
{
    public function __construct(public string $text)
    {
    }
}
