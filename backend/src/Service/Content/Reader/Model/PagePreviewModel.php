<?php

declare(strict_types=1);

namespace App\Service\Content\Reader\Model;

use App\Entity\LinkCardContent;
use App\Enum\FeedLanguage;

/** The card a page declares for itself, unbounded, and the language it states. */
final readonly class PagePreviewModel
{
    public function __construct(
        public string $url,
        public string $title,
        public ?string $description,
        public ?string $imageUrl,
        public string $siteName,
        public ?FeedLanguage $language,
    ) {
    }

    public function card(): LinkCardContent
    {
        return new LinkCardContent($this->url, $this->title, $this->description, $this->imageUrl, $this->siteName);
    }
}
