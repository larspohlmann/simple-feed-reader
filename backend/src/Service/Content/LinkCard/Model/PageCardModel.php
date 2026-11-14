<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard\Model;

use App\Entity\EntryMedium;
use App\Entity\LinkCardContent;

final readonly class PageCardModel
{
    public function __construct(
        public ?LinkCardContent $card,
        public ?EntryMedium $medium,
    ) {
    }
}
