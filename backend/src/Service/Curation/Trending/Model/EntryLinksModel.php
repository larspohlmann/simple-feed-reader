<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Service\Curation\Trending\Support\ArticleSite;

/** What a window entry links to: its own article, and the article its first card shares. */
final readonly class EntryLinksModel
{
    /** @param ?string $articleUrl the entry's own URL when its feed publishes articles; null for a social post */
    public function __construct(
        public ?string $urlHash,
        public ?string $articleUrl,
        public ?WindowLinkCardModel $card,
    ) {
    }

    public function storyCard(): ?WindowLinkCardModel
    {
        return $this->card?->namesAStory() === true ? $this->card : null;
    }

    public function articleSite(): ?string
    {
        return $this->articleUrl === null ? $this->storyCard()?->site() : ArticleSite::of($this->articleUrl);
    }
}
