<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\EntryMedium;
use App\Service\LinkCard\Model\PlacedLinkCardModel;

final readonly class ThreadPostModel
{
    /** @param list<EntryMedium> $media */
    public function __construct(
        public ?string $url,
        public ?\DateTimeImmutable $publishedAt,
        public string $html,
        public array $media,
        public ?PlacedLinkCardModel $linkCard,
    ) {
    }
}
