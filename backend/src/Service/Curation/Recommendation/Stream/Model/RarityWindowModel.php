<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Model;

/** The entries rarity samples from: effective since $since, with ids above $afterId. */
final readonly class RarityWindowModel
{
    public function __construct(public \DateTimeImmutable $since, public int $afterId)
    {
    }
}
