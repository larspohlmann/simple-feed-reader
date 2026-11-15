<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Enum\FeedLanguage;

/** The article a post's story card shares, ready to stem in its own language. */
final readonly class SharedArticleModel
{
    public function __construct(public string $title, public string $body, public ?FeedLanguage $language)
    {
    }
}
