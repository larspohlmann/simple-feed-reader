<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

/** When a story first formed, and when an article last joined it. */
final readonly class StoryLifespanModel
{
    public function __construct(
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $lastAddedAt,
    ) {
    }
}
