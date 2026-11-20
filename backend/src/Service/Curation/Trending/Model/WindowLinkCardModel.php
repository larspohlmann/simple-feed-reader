<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Enum\FeedLanguage;
use App\Service\Content\LinkCard\Support\StoryLinkRule;
use App\Service\Curation\Trending\Support\ArticleSite;

/** An entry's first top-level link card, as the window reads it from its shared link. */
final readonly class WindowLinkCardModel
{
    public ?string $site;

    public function __construct(
        public int $sharedLinkId,
        public string $urlHash,
        public string $url,
        public string $title,
        public ?string $description,
        public string $siteName,
        public ?FeedLanguage $language,
    ) {
        $this->site = ArticleSite::fromUrl($url);
    }

    public function namesAStory(): bool
    {
        return StoryLinkRule::namesAStory($this->url, $this->title, $this->siteName);
    }
}
