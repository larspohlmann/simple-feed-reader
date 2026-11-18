<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class MemberHeadlineModel
{
    public function __construct(
        public int $entryId,
        public string $title,
        public ?string $feedTitle,
    ) {
    }
}
