<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Enum\FeedLanguage;

final readonly class StoryMemberModel
{
    public function __construct(
        public int $entryId,
        public int $feedId,
        public ?string $articleSite,
        public ?int $sharedLinkId = null,
        public ?FeedLanguage $language = null,
        public bool $fromAnOutlet = false,
        public ?string $urlHash = null,
    ) {
    }
}
