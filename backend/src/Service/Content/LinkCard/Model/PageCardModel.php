<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard\Model;

use App\Entity\EntryMedium;
use App\Entity\LinkCardContent;
use App\Entity\SharedLink;
use App\Enum\FeedLanguage;

final readonly class PageCardModel
{
    public function __construct(
        public ?LinkCardContent $card,
        public ?EntryMedium $medium,
        public ?PageIdentityModel $identity = null,
        public ?FeedLanguage $statedLanguage = null,
        public ?SharedLink $link = null,
    ) {
    }

    public static function known(SharedLink $link): self
    {
        return new self($link->preview(), null, link: $link);
    }
}
