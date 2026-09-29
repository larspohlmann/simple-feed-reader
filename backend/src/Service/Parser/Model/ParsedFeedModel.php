<?php

declare(strict_types=1);

namespace App\Service\Parser\Model;

final readonly class ParsedFeedModel
{
    /**
     * @param list<ParsedEntryModel> $entries
     */
    public function __construct(
        public ?string $title,
        public ?string $siteUrl,
        public ?string $description,
        public ?string $imageUrl,
        public array $entries,
    ) {
    }

    /**
     * The same feed with a different entry list, so a caller that narrows the entries keeps every other field.
     *
     * @param list<ParsedEntryModel> $entries
     */
    public function withEntries(array $entries): self
    {
        return new self($this->title, $this->siteUrl, $this->description, $this->imageUrl, $entries);
    }
}
