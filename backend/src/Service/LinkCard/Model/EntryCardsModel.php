<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Model;

use App\Entity\Entry;
use App\Entity\LinkCardContent;

/** An entry's stored cards and the page cards just read for its uncarded links. */
final readonly class EntryCardsModel
{
    /**
     * @param list<LinkCardContent> $stored    in post order
     * @param list<PageCardModel>   $pageCards in the order of the links they were read for
     */
    public function __construct(
        public Entry $entry,
        public array $stored,
        public array $pageCards,
    ) {
    }

    /** @return list<LinkCardContent> */
    public function newCards(): array
    {
        return array_values(array_filter(array_map(
            static fn (PageCardModel $pageCard): ?LinkCardContent => $pageCard->card,
            $this->pageCards,
        )));
    }
}
