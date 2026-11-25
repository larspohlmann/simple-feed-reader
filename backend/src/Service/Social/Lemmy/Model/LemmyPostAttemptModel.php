<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy\Model;

final readonly class LemmyPostAttemptModel
{
    public function __construct(
        public string $title,
        public int $communityId,
        public \DateTimeImmutable $sentAt,
    ) {
    }
}
