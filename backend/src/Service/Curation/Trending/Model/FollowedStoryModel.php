<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class FollowedStoryModel
{
    public function __construct(public FollowedStoryRowModel $row, public bool $quiet)
    {
    }
}
