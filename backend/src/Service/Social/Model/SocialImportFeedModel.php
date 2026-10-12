<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class SocialImportFeedModel
{
    public function __construct(
        public string $feedUrl,
        public ?string $title,
    ) {
    }
}
