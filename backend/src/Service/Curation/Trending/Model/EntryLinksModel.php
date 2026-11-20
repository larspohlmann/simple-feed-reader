<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Service\Curation\Trending\Support\ArticleSite;

/** What a window entry links to: its own article, and the article its first card shares. */
final readonly class EntryLinksModel
{
    private ?WindowLinkCardModel $storyCard;
    private ?string $articleSite;

    /** @param ?string $articleUrl the entry's own URL when its feed publishes articles; null for a social post */
    public function __construct(
        public ?string $urlHash,
        public ?string $articleUrl,
        public ?WindowLinkCardModel $card,
    ) {
        $this->storyCard = $card?->namesAStory() === true ? $card : null;
        $this->articleSite = $articleUrl === null ? $this->storyCard?->site : ArticleSite::fromUrl($articleUrl);
    }

    public function storyCard(): ?WindowLinkCardModel
    {
        return $this->storyCard;
    }

    public function articleSite(): ?string
    {
        return $this->articleSite;
    }

    /** @return list<string> */
    public function urlHashes(): array
    {
        return array_values(array_unique(array_filter(
            [$this->urlHash, $this->card?->urlHash],
            static fn (?string $urlHash): bool => $urlHash !== null,
        )));
    }
}
