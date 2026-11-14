<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class FollowedAccountModel
{
    public function __construct(
        public string $identity,
        public string $handle,
        public ?string $displayName,
        public string $feedUrl,
        public ?string $avatarUrl,
    ) {
    }
}
