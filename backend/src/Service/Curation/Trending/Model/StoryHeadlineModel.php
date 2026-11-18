<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Enum\FeedLanguage;

/** One member's headline as a story AI task reads it, with the opening of its body and the outlet it came from. */
final readonly class StoryHeadlineModel
{
    public function __construct(
        public string $headline,
        public string $snippet,
        public ?string $feedTitle = null,
        public ?FeedLanguage $language = null,
    ) {
    }

    #[\NoDiscard]
    public function cleaned(string $headline): self
    {
        return clone($this, ['headline' => $headline]);
    }
}
