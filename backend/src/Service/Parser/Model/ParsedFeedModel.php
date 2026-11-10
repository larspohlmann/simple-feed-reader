<?php

declare(strict_types=1);

namespace App\Service\Parser\Model;

use App\Service\Image\Model\DeclaredImageModel;

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
        public ?string $generator = null,
    ) {
    }

    /** @param list<ParsedEntryModel> $entries */
    #[\NoDiscard]
    public function withEntries(array $entries): self
    {
        return clone($this, ['entries' => $entries]);
    }

    #[\NoDiscard]
    public function withShowArtwork(?DeclaredImageModel $showArtwork): self
    {
        return $this->withEntries(array_map(
            static fn (ParsedEntryModel $entry): ParsedEntryModel => $entry->withShowArtwork($showArtwork),
            $this->entries,
        ));
    }
}
