<?php

declare(strict_types=1);

namespace App\Service\Social\Publishing\Model;

/** Where a Lemmy post goes and what it is called. */
final readonly class LemmyCommunityPostModel
{
    public function __construct(
        public ?int $communityId,
        public ?string $title,
    ) {
    }
}
