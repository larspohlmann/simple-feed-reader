<?php

declare(strict_types=1);

namespace App\Service\Trending\Model;

final readonly class StoryMemberModel
{
    public function __construct(public int $entryId, public int $feedId)
    {
    }
}
