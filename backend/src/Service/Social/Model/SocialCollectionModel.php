<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class SocialCollectionModel
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $avatarUrl = null,
    ) {
    }
}
