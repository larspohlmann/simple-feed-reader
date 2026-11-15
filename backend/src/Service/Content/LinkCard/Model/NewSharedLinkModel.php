<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard\Model;

use App\Entity\LinkCardContent;
use App\Entity\LinkLanguage;

final readonly class NewSharedLinkModel
{
    public function __construct(
        public string $urlHash,
        public LinkCardContent $preview,
        public ?LinkLanguage $language,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
