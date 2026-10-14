<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Model;

use App\Entity\LinkCardContent;

/** An entry's stored cards and the page cards just read for its uncarded links. */
final readonly class EntryCardsModel
{
    /**
     * @param list<LinkCardContent> $stored    in post order
     * @param list<PageCardModel>   $pageCards in the order of the links they were read for
     */
    public function __construct(
        public array $stored,
        public array $pageCards,
        public ?QuotedTargetModel $quoted = null,
    ) {
    }

    public static function quoting(QuotedTargetModel $quoted): self
    {
        return new self([], [], $quoted);
    }

    /** @return list<string> the addresses whose "RE:" line the cards and the quoted post replace */
    public function quoteLineUrls(): array
    {
        return [
            ...array_map(static fn (LinkCardContent $card): string => $card->url, $this->newCards()),
            ...($this->quoted === null ? [] : [$this->quoted->address]),
        ];
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
