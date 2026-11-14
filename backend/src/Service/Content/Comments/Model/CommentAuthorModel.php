<?php

declare(strict_types=1);

namespace App\Service\Comments\Model;

final readonly class CommentAuthorModel
{
    public function __construct(
        public ?string $name,
        public ?string $handle = null,
        public ?string $profileUrl = null,
        public ?string $avatarUrl = null,
    ) {
    }
}
