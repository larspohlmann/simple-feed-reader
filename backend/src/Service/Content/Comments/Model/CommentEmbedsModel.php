<?php

declare(strict_types=1);

namespace App\Service\Comments\Model;

use App\Entity\EntryMedium;
use App\Entity\LinkCardContent;
use App\Entity\QuotedPostContent;

final readonly class CommentEmbedsModel
{
    /** @param list<EntryMedium> $media */
    public function __construct(
        public array $media = [],
        public ?LinkCardContent $linkCard = null,
        public ?QuotedPostContent $quote = null,
    ) {
    }
}
