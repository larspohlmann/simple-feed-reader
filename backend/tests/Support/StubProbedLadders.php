<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\ImageRendition;
use App\Service\Image\ProbedLadder\ProbedLadderInterface;

final class StubProbedLadders implements ProbedLadderInterface
{
    /** @var array<string, list<ImageRendition>> */
    private array $laddersByImageUrl = [];

    /** @param list<ImageRendition> $ladder */
    public function willHand(string $imageUrl, array $ladder): void
    {
        $this->laddersByImageUrl[$imageUrl] = $ladder;
    }

    public function findProbedLadder(string $imageUrl): ?array
    {
        return $this->laddersByImageUrl[$imageUrl] ?? null;
    }
}
